<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Models\Nasabah;
use Illuminate\Http\JsonResponse;

class CheckIfNasabah
{
    private $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 5, // Timeout 5 detik untuk menghindari request yang terlalu lama
        ]);
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('Authorization');

        if (!$token) {
            return $this->errorResponse("Token Authorization tidak ditemukan.");
        }

        try {
            $response = $this->client->request("POST", env('AUTH_BASE_URI')."/api/v1/auth/cek-token", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => $token
                ],
            ]);

            $data = json_decode($response->getBody(), true) ?? [];
            if (!isset($data['status']) || empty($data['data']['user']['id'])) {
                return $this->errorResponse("Data user tidak valid atau tidak ditemukan.");
            }

            // Check if user has the 'nasabah' role
            if (!isset($data['data']['roles']['is_nasabah']) || $data['data']['roles']['is_nasabah'] !== true) {
                return $this->errorResponse("Akses ditolak. Anda bukan pengguna Nasabah.");
            }

            $nasabah_user = Nasabah::where("user_id", $data['data']['user']['id']);

            if($nasabah_user->exists()) {
                $request->attributes->add(
                    [
                        "user_id" => $data['data']['user']['id'],
                        "user" => $data['data']['user'],
                        "token" => $token
                    ]
                );    
                return $next($request);       
             } else {
                return $this->errorResponse("User tidak memiliki profil Nasabah.");
             }
        } catch (RequestException $e) {
            return response()->json([
                "status" => false,
                "message" => "Gagal menghubungi server autentikasi",
                "error" => $e->getMessage(),
                "profile_profile" => false
            ], 500);
        }
    }

    private function errorResponse(string $message): JsonResponse
    {
        return response()->json([
            "status" => false,
            "message" => $message,
            "nasabah_profile" => false
        ], 400);
    }
}
