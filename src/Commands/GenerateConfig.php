<?php

namespace APPelit\SRP\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use phpseclib\Math\BigInteger;

class GenerateConfig extends Command
{
    const START_MARKER = '-----BEGIN DH PARAMETERS-----';
    const END_MARKER = '-----END DH PARAMETERS-----';
    const ALGORITHM = 'sha256';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'srp:generate
     {--s|show : Display the parameters instead of modifying files.}
     {--f|force : Answer yes on all questions.}
     {--F|file= : DHParams file to use instead of automatic generation.}
     {--g|generator=5 : Generator to use (2 or 5 are recommended.}
     {--b|bits=4096 : The size of the prime.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the required configuration for SRP';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $envPath = $path = $this->envPath();
        $update = false;

        if (
            $envPath &&
            File::exists($envPath) &&
            ($update = Str::contains(file_get_contents($envPath), 'SRP_')) &&
            !$this->option('show') &&
            !$this->option('force') &&
            !$this->confirm('Are you sure you want to replace your current parameters, this will break any existing passwords')
        ) {
            $this->info("Parameter generation skipped, file remains intact");
            return;
        }

        if ($file = $this->option('file')) {
            if (!File::exists($file)) {
                $this->error('Could not find the specified file');
                return;
            }

            if (!File::isReadable($file)) {
                $this->error('The specified is unreadable');
                return;
            }

            $this->info("Parsing file {$file}");

            $contents = File::get($file);
            $start = strpos($contents, static::START_MARKER);
            if ($start === false) {
                $this->error('Could not determine start marker, ensure the file is a DH parameters file in PEM encoding');
                return;
            }
            $start += strlen(static::START_MARKER) + 1;
            $end = strpos($contents, static::END_MARKER, $start);
            if ($end === false) {
                $this->error('Could not determine end marker, ensure the file is a DH parameters file in PEM encoding');
                return;
            }

            $der = base64_decode(str_replace("\n", '', substr($contents, $start, $end - $start)), true);
            if (!$der) {
                $this->error('DER could not be read or decoded, ensure the file is a DH parameters file in PEM encoding');
                return;
            }

            $asn1 = new \phpseclib\File\ASN1;
            $decoded = $asn1->decodeBER($der);
            $N = array_get($decoded, '0.content.0.content');
            $g = array_get($decoded, '0.content.1.content');
            if (!$N || !$g || !($N instanceof BigInteger) || !($g instanceof BigInteger)) {
                $this->error('Unable to extract N and g from file');
                return;
            }
        } else {
            if (
                !$this->option('force') &&
                !$this->confirm('Generating DH parameters using PHP is a (very) slow process, we recommend using "openssl dhparam" to generate the parameters instead, are you sure you want to continue?')
            ) {
                return;
            }

            $this->info('Generating DH parameters, this will take a long time');

            $generator = $this->option('generator');
            if (filter_var($generator, FILTER_VALIDATE_INT) === false) {
                $this->error('Generator should be an integer');
                return;
            }

            $generator = intval($generator);
            if ($generator < 2) {
                $this->error('Generator should be at least 2');
                return;
            }

            $g = new BigInteger($generator);

            $bits = $this->option('bits');
            if (filter_var($bits, FILTER_VALIDATE_INT) === false) {
                $this->error('Bits should be an integer');
                return;
            }

            $bits = intval($bits);
            if ($bits < 1024 || ($bits & ($bits - 1)) !== 0) {
                $this->error('Bits should be a power of 2 and at least 1024');
                return;
            }

            // To generate a safe prime of $bits length, the bounds of the smaller prime must be $bits - 1
            $min = new BigInteger(str_pad(1, $bits, 0, STR_PAD_RIGHT), 2);
            $max = new BigInteger(str_pad(1, $bits, 1, STR_PAD_RIGHT), 2);

            // Since we need these multiple times, define them once
            $zero = new BigInteger('0');
            $one = new BigInteger('1');
            $two = new BigInteger('2');

            $primes = [];
            $safePrime = new BigInteger;
            while (true) {
                /** @var BigInteger $safePrime */
                if (($safePrime = $safePrime->randomPrime($min, $max)) === false) {
                    $this->error('Could not determine a prime number within range');
                    return;
                }

                // We already had this prime, just skip it
                if (in_array($hex = $safePrime->toHex(), $primes)) {
                    $this->output->write('-');
                    continue;
                }
                $primes[] = $hex;

                /** @var BigInteger $prime */
                /** @var BigInteger $remainder */
                list($prime, $remainder) = $safePrime->subtract($one)->divide($two);
                if ($remainder->compare($zero) !== 0 || !$prime->isPrime()) {
                    // The prime is not a prime or has a remainder, skip it
                    $this->output->write('.');
                    continue;
                }

                // Verify this prime against the generator
                if (
                    $one->compare($g->powMod($prime, $safePrime)) !== 0 &&
                    $one->compare($g->powMod($two, $safePrime)) !== 0
                ) {
                    break;
                }

                $this->output->write('+');
            }

            $this->output->write(PHP_EOL);

            $N = $safePrime;
        }

        $k = $this->computeK($N, $g);

        $algorithm = static::ALGORITHM;

        if (
            $this->option('show') ||
            !$envPath ||
            !File::exists($envPath)
        ) {
            $this->info('Parameters:');
            $this->info("\tSRP_N={$N->toString()}");
            $this->info("\tSRP_G={$g->toString()}");
            $this->info("\tSRP_K={$k}");
            $this->info("\tSRP_H={$algorithm}");
            return;
        }

        if ($update) {
            // At least one variable is already defined

            $contents = file_get_contents($envPath);
            if (Str::contains($contents, 'SRP_N=')) {
                $contents = preg_replace("#SRP_N=.*\n#", "SRP_N={$N->toString()}\n", $contents);
            } else {
                $contents .= PHP_EOL . "SRP_N={$N->toString()}";
            }

            if (Str::contains($contents, 'SRP_G=')) {
                $contents = preg_replace("#SRP_G=.*\n#", "SRP_G={$g->toString()}\n", $contents);
            } else {
                $contents .= PHP_EOL . "SRP_G={$g->toString()}";
            }

            if (Str::contains($contents, 'SRP_K=')) {
                $contents = preg_replace("#SRP_K=.*\n#", "SRP_K={$k}\n", $contents);
            } else {
                $contents .= PHP_EOL . "SRP_K={$k}";
            }

            if (Str::contains($contents, 'SRP_H=')) {
                $contents = preg_replace("#SRP_H=.*\n#", "SRP_H={$algorithm}\n", $contents);
            } else {
                $contents .= PHP_EOL . "SRP_H={$algorithm}";
            }

            $contents = rtrim($contents, PHP_EOL) . PHP_EOL;

            file_put_contents($envPath, $contents);
        } else {
            $config = <<<EOD

SRP_N={$N->toString()}
SRP_G={$g->toString()}
SRP_K={$k}
SRP_H={$algorithm}

EOD;

            file_put_contents($envPath, $config, FILE_APPEND);
        }
    }

    /**
     * @param BigInteger $N
     * @param BigInteger $g
     * @return string
     */
    private function computeK(BigInteger $N, BigInteger $g): string
    {
        $N_bytes = $N->toBytes();
        $g_bytes = $g->toBytes();

        $g_bytes = str_pad($g_bytes, strlen($N_bytes), "\0", STR_PAD_LEFT);

        $context = hash_init(static::ALGORITHM);
        hash_update($context, $N_bytes);
        hash_update($context, $g_bytes);

        return ltrim(hash_final($context, false), '0');
    }

    /**
     * Get the .env file path.
     *
     * @return string
     */
    private function envPath()
    {
        if (method_exists($this->laravel, 'environmentFilePath')) {
            return $this->laravel->environmentFilePath();
        }
        // check if laravel version Less than 5.4.17
        if (version_compare($this->laravel->version(), '5.4.17', '<')) {
            return $this->laravel->basePath() . DIRECTORY_SEPARATOR . '.env';
        }
        return $this->laravel->basePath('.env');
    }
}
