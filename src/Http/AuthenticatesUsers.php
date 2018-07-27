<?php

namespace APPelit\SRP\Http\Controllers;

use APPelit\SRP\Exceptions\SrpException;
use APPelit\SRP\SRP;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Auth\RedirectsUsers;
use Illuminate\Foundation\Auth\ThrottlesLogins;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait AuthenticatesUsers
{
    use RedirectsUsers;
    use ThrottlesLogins;
    use ValidatesRequests;

    /**
     * @param \Illuminate\Contracts\Container\Container $app
     * @param \Illuminate\Http\Request $request
     * @return mixed
     * @throws ValidationException
     */
    public function challenge(Container $app, Request $request)
    {
        $this->validateChallenge($request);

        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);
            $this->sendLockoutResponse($request);
            return null;
        }

        $user = $this->retrieveUserByCredentials($request);

        // Verify the required data, if any is missing assume a developer error
        if (
            !($identifier = $this->identifier($app, $user)) ||
            !($salt = $this->salt($app, $user)) ||
            !($verifier = $this->verifier($app, $user))
        ) {
            throw new \RuntimeException(sprintf(
                'Invalid user, missing identifier, salt or verifier',
                get_class($user)
            ));
        }

        // Perform the authentication using the first step of the SRP server flow
        try {
            $response = SRP::challenge($identifier, $salt, $verifier);
        } catch (SrpException $e) {
            $this->sendFailedLoginResponse($request);
            return null;
        }

        // Respond with success and the parameters required by the client for further processing
        return $this->sendChallengeResponse($request, $salt, $response->getB(), $response->getSession());
    }

    /**
     * @param \Illuminate\Contracts\Container\Container $app
     * @param \Illuminate\Http\Request $request
     * @return mixed
     */
    public function authenticate(Container $app, Request $request)
    {
        $this->validateAuthenticate($request);

        // Perform the authentication using the second step of the SRP server flow
        try {
            $response = SRP::authenticate($request->input('session'), $request->input('A'), $request->input('M1'));
        } catch (SrpException $e) {
            $this->sendFailedLoginResponse($request);
            return null;
        }

        // The authentication is verified, retrieve the user and log him in
        $this->guard($app)->login($user = $this->retrieveUserByCredentials($request));

        // Give the developer a chance to save the session key for later use
        $this->sessionKey($request, $user, $response->getSessionKey());

        // Respond with success and the 'M2' server proof
        return $this->sendAuthenticateResponse($request, $response->getM2());
    }

    /**
     * Log the user out of the application.
     *
     * @param \Illuminate\Contracts\Container\Container $app
     * @param \Illuminate\Http\Request $request
     * @return mixed
     */
    public function logout(Container $app, Request $request)
    {
        $this->guard($app)->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
        }

        if ($response = $this->loggedOut($request)) {
            return $response;
        }

        if ($request->expectsJson()) {
            return response()->json(null, JsonResponse::HTTP_NO_CONTENT);
        }

        return redirect('/');
    }

    /**
     * Validate the user challenge request.
     *
     * @param  \Illuminate\Http\Request $request
     * @return void
     */
    protected function validateChallenge(Request $request)
    {
        $this->validate($request, [
            $this->username() => 'required|string',
        ]);

        if (count($properties = $request->input()) !== 1) {
            throw new \RuntimeException(
                "Providing more than {$this->username()} to challenge is insecure and thus an error"
            );
        }
    }

    /**
     * Validate the user challenge request.
     *
     * @param  \Illuminate\Http\Request $request
     * @return void
     */
    protected function validateAuthenticate(Request $request)
    {
        $this->validate($request, [
            'session' => 'required|string',
            'A' => 'required|string',
            'M1' => 'required|string',
            $this->username() => 'required|string',
        ]);

        if (count($properties = $request->input()) !== 3) {
            throw new \RuntimeException(
                "Providing more than session, A, M1 and {$this->username()} to authenticate is insecure and thus an error"
            );
        }
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * @param  \Illuminate\Http\Request $request
     * @return array
     */
    protected function credentials(Request $request)
    {
        return $request->only($this->username());
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    protected function username(): string
    {
        return 'email';
    }

    /**
     * Get the identifier from the user, override this method if the identifier is not stored under the
     * '{$this->username()}' attribute
     *
     * @param \Illuminate\Contracts\Container\Container $app
     * @param \Illuminate\Contracts\Auth\Authenticatable|object $user
     * @return string
     */
    protected function identifier(Container $app, Authenticatable $user): string
    {
        return $user->{$this->username()};
    }

    /**
     * Get the salt from the user, override this method if the salt is not stored under the 'salt' attribute
     *
     * @param \Illuminate\Contracts\Container\Container $app
     * @param \Illuminate\Contracts\Auth\Authenticatable|object $user
     * @return string
     */
    protected function salt(Container $app, Authenticatable $user): string
    {
        return $user->salt;
    }

    /**
     * Get the verifier from the user, override this method if the verifier is not stored encrypted or under the
     * 'verifier' attribute
     *
     * @param \Illuminate\Contracts\Container\Container $app
     * @param \Illuminate\Contracts\Auth\Authenticatable|object $user
     * @return string
     */
    protected function verifier(Container $app, Authenticatable $user): string
    {
        /** @var Encrypter $encrypter */
        $encrypter = $app->make('encrypter');

        // Per best practices the verifier should be encrypted, use the illuminate encrypter to decrypt the verifier
        return $encrypter->decrypt($user->verifier, false);
    }

    /**
     * Override this method if you need to save the session key to use later in the application, uses can include
     * message signing using HMAC or encryption using a symmetric algorithm
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Contracts\Auth\Authenticatable|object $user
     * @param string $sessionKey
     */
    protected function sessionKey(Request $request, Authenticatable $user, string $sessionKey)
    {
    }

    /**
     * Send the response after the challenge was calculated.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $salt
     * @param string $B
     * @param string $session
     * @return mixed
     */
    protected function sendChallengeResponse(Request $request, string $salt, string $B, string $session)
    {
        $data = compact('salt', 'B', 'session');

        if ($request->expectsJson()) {
            return response()->json($data);
        }

        // Redirect the user back but flash the data into the session to use for further processing by the client
        return redirect()->back()->with($data);
    }

    /**
     * Send the response after the authentication was validated.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $M2
     * @return mixed
     */
    protected function sendAuthenticateResponse(Request $request, string $M2)
    {
        $this->clearLoginAttempts($request);

        if ($response = $this->authenticated($request, $this->guard()->user(), $M2)) {
            return $response;
        }

        $data = compact('M2');

        if ($request->expectsJson()) {
            // In case this is an AJAX request
            return response()->json($data);
        }

        return redirect()->intended($this->redirectPath())->with($data);
    }

    /**
     * Get the failed login response instance.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    protected function sendFailedLoginResponse(Request $request)
    {
        $this->incrementLoginAttempts($request);

        throw ValidationException::withMessages([
            $this->username() => [trans('auth.failed')],
        ]);
    }

    /**
     * The user has been authenticated.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     * @param string $M2
     * @return mixed
     */
    protected function authenticated(Request $request, Authenticatable $user, string $M2)
    {
        return null;
    }

    /**
     * The user has logged out of the application.
     *
     * @param  \Illuminate\Http\Request $request
     * @return mixed
     */
    protected function loggedOut(Request $request)
    {
        return null;
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @param \Illuminate\Contracts\Container\Container $app
     * @return \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard(Container $app): \Illuminate\Contracts\Auth\Guard
    {
        /** @var \Illuminate\Contracts\Auth\Factory $auth */
        $auth = $app->make('auth');

        return $auth->guard();
    }

    /**
     * Get the user provider belonging to the specified guard
     *
     * @return \Illuminate\Contracts\Auth\UserProvider
     */
    protected function userProvider(): UserProvider
    {
        $guard = $this->guard();

        if (!method_exists($guard, 'getProvider')) {
            throw new \RuntimeException('Expected the guard to have a getProvider method, please use a guard that implements this method or override the controller');
        }

        return $guard->getProvider();
    }

    /**
     * Retrieve the user by his credentials
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\Auth\Authenticatable
     */
    protected function retrieveUserByCredentials(Request $request): Authenticatable
    {
        if (!($user = $this->userProvider()->retrieveByCredentials($this->credentials($request)))) {
            $this->sendFailedLoginResponse($request);
        }

        return $user;
    }
}
