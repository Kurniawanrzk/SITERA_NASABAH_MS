<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;

class CheckIfBSU
{
    /**
     * HTTP Client instance
     * 
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 5, // Timeout 5 detik untuk menghindari request yang terlalu lama
        ]);
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $token = $request->header('Authorization');

        if(!$token) {
            return $this->errorResponse("Token Authorization Tidak Ditemukan", 401);
        }

        try {
            // Verifikasi token dengan auth microservice
            $authResponse = $this->client->request("POST", "http://145.79.10.111:8002/api/v1/auth/cek-token", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => $token
                ],
            ]);
            
            $authData = json_decode($authResponse->getBody(), true);
            
            if (!isset($authData['status']) || $authData['status'] !== true) {
                return $this->errorResponse("Token tidak valid", 401);
            }
            
            // Token valid, cek apakah user adalah BSU
            if (!isset($authData['data']['roles']['is_bsu']) || $authData['data']['roles']['is_bsu'] !== true) {
                return $this->errorResponse("Akses ditolak. Anda bukan pengguna BSU.", 403);
            }
            
            // Tambahkan user_id dan bsu_id ke request untuk digunakan di controller
            $request->merge([
                'user_id' => $authData['data']['user']['id'] ?? null,
                'bsu_id' => $authData['data']['roles']['bsu_id'] ?? null,
                'token' => $request->header('Authorization') ?? null
            ]);
            
            return $next($request);
            
        } catch (RequestException $e) {
            // Log error jika diperlukan
            \Log::error('Auth service error: ' . $e->getMessage());
            
            if ($e->hasResponse()) {
                $errorResponse = json_decode($e->getResponse()->getBody(), true);
                return $this->errorResponse($errorResponse['message'] ?? "Gagal memverifikasi token", 401);
            }
            
            return $this->errorResponse( $e->getMessage(), 500);
        } catch (\Exception $e) {
            // Log error jika diperlukan
            \Log::error('Unexpected error: ' . $e->getMessage());
            return $this->errorResponse("Terjadi kesalahan sistem. Silakan coba lagi.", 500);
        }
    }

    /**
     * Return error response
     *
     * @param string $message
     * @param int $statusCode
     * @return \Illuminate\Http\JsonResponse
     */
    private function errorResponse(string $message, int $statusCode = 400): JsonResponse
    {
        return response()->json([
            "status" => false,
            "message" => $message,
        ], $statusCode);
    }
}