<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Login extends ApiController
{
    private $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function SignIn(Request $request)
    {
        $request->validate([
            'email' => 'required|max:255',
            'password' => 'required|min:8',
        ]);

        $data = $this->authService->signIn($request->post('email'), $request->post('password'));
        if (!$data) {
            $data = [
                'status' => 'error',
                'msg' => 'Unathorized',
                'errors' => null,
                'content' => null,
            ];
            return $this->errorResponse($data, 'Username atau password salah', 422);
        }

        return $this->successResponse($data, 'Login Berhasil');
    }

    public function SignOut(Request $request)
    {
        $this->authService->signOut($request->user());
        return $this->successResponse(null, 'Logout Berhasil');
    }

    public function SignInGoogle(Request $request)
    {
        $result = $this->authService->signInGoogle((string) $request->post('access_token'));
        if (!$result['success']) {
            return $this->errorResponse(null, $result['message'], $result['code']);
        }

        return $this->successResponse($result['data'], $result['message']);
    }

   public function CheckWhatsappNumber($phone)
{
    try {
        $result = $this->authService->checkWhatsappNumber($phone);

        return $this->successResponse([
            'registered' => $result['registered'],
            'phone' => $result['phone'],
            'user_exists' => $result['user_exists']
        ], $result['message']);

    } catch (\Exception $e) {
        return $this->errorResponse(null, 'Format nomor tidak valid: ' . $e->getMessage(), 400);
    }
}

public function RequestWhatsappOtp(Request $request)
{
    $validator = Validator::make($request->all(), [
        'phone' => 'required|string'
    ]);

    if ($validator->fails()) {
        return $this->errorResponse($validator->errors(), 'Nomor WhatsApp diperlukan', 422);
    }

    try {
        $result = $this->authService->requestWhatsappOtp((string) $request->phone);
        if (!$result['success']) {
            return $this->errorResponse(null, $result['message'], $result['code']);
        }

        return $this->successResponse($result['data'], $result['message']);

    } catch (\Exception $e) {
        return $this->errorResponse(null, 'Gagal mengirim OTP: ' . $e->getMessage(), 400);
    }
}

public function SignInWhatsapp(Request $request)
{
    $validator = Validator::make($request->all(), [
        'phone' => 'required|string',
        'otp' => 'required|string|size:6'
    ]);

    if ($validator->fails()) {
        return $this->errorResponse($validator->errors(), 'Validasi gagal', 422);
    }

    try {
        $result = $this->authService->signInWhatsapp((string) $request->phone, (string) $request->otp);
        if (!$result['success']) {
            return $this->errorResponse(null, $result['message'], $result['code']);
        }

        return $this->successResponse($result['data'], $result['message']);

    } catch (\Exception $e) {
        return $this->errorResponse(null, 'Verifikasi gagal: ' . $e->getMessage(), 400);
    }
}
}
