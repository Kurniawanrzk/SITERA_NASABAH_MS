<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Models\Nasabah;
use Illuminate\Support\Facades\Hash; 

class NasabahController extends Controller
{
    public function buatAkunNasabahCSV(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt',
        ]);
    
        $file = $request->file('file');
        $path = $file->getRealPath();
        $data = array_map('str_getcsv', file($path));
        
        $success = 0;
        $failed = 0;
        $failedRows = [];
        
        $client_auth_sitera = new Client([
            "timeout" => 5
        ]);
    
        foreach ($data as $index => $row) {
            // Skip header row if exists
            if ($index === 0 && in_array('email', $row)) {
                continue;
            }
            
            try {
                $email = $row[0];
                $password = $row[1];
                
                // Make API request to register user
                $response_auth_sitera = $client_auth_sitera->request("POST","http://145.79.10.111:8002/api/v1/auth/register", [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => $request->get("token")
                    ],
                    'json' => [
                        "email" => $email,
                        "password" => Hash::make($password),
                        "role" => "nasabah"
                    ]
                ]);
                
                $data_response = json_decode($response_auth_sitera->getBody())->data->user;
                
                // Create Nasabah record
                $user_nasabah = new Nasabah;
                $user_nasabah->user_id = $data_response->id;
                $user_nasabah->bsu_id = $request->get("bsu_user")['id'];
                $user_nasabah->save();
                
                $success++;
                
            } catch (RequestException $e) {
                $failed++;
                $failedRows[] = [
                    'row' => $index + 1,
                    'email' => $row[0] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            } catch (\Exception $e) {
                $failed++;
                $failedRows[] = [
                    'row' => $index + 1,
                    'email' => $row[0] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }
    
        return response()->json([
            "status" => true,
            "message" => "Proses pembuatan akun nasabah dari CSV selesai",
            "data" => [
                "success" => $success,
                "failed" => $failed,
                "failed_details" => $failedRows
            ]
        ], 200);
    }

    public function buatAkunNasabah(Request $request)
    {

        $client_auth_sitera = new Client([
            "timeout" => 5
        ]);
        
        try {
            $response_auth_sitera = $client_auth_sitera->request("POST", "http://145.79.10.111:8002/api/v1/auth/register", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => $request->get("token")
                ],
                'json' => [ 
                    "email" => $request->email,
                    "password" => Hash::make($request->password),
                    "role" => "nasabah"
                ]
            ]);
            
            $data_response = json_decode($response_auth_sitera->getBody())->data->user;

            $user_nasabah = new Nasabah;
            $user_nasabah->user_id = $data_response->id; // Corrected property assignment
            $user_nasabah->bsu_id = $request->get("bsu_id");
            $user_nasabah->nik = $request->nik;
            $user_nasabah->nik = $request->nama;
            $user_nasabah->save(); // Save the Nasabah model
            
            return $data_response;
            
        } catch (RequestException $e) {
            // Add error handling
            return response()->json([
                'success' => false,
                'message' => 'Failed to create account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function cekProfileNasabah(Request $request)
    {   
        return response()->json([
            "status" => true,
            "data" => [
                    "user_nasabah" => Nasabah::where("user_id", $request->get("user_id"))->first(),
                    "user" => $request->get('user')
            ]
        ], 200);
    }

    public function cekNasabahDariNIK($nik, Request $request) 
    {
        $nasabah = Nasabah::where("nik", $nik);
        if($nasabah->exists()) {
            return response()->json([
                "status" => true,
                "data" => [
                        "user_nasabah" => $nasabah->first(),
                ]
            ], 200);
        } else {
            return response()->json([
                "status" =>  false,
                "data" => [
                        "user_nasabah" => $nasabah->first(),
                ]
            ], 400);
        }
    }

    public function cekSemuaNasabahBerdasarkanBSU(Request $request)
    {

        $nasabah = Nasabah::where("bsu_id", $request->get("bsu_id"))->get();
        
        return response()->json([
            "status" => true,
            "data" => [
                    "nasabah" =>  $nasabah,
            ]
        ], 200);
    }

    public function cekUser($user_id)
    {
        $nasabah = Nasabah::where("user_id", $user_id);

        if($nasabah->exists())
        {
            return response()
            ->json([
                'status' => true,
                'data' => $nasabah->first()
            ], 200);
        } else {
            return responses()
            ->json([
                'status' => false,
                'message' => "user tidak ada"
            ], 401);
        }
    }

    public function editProfilNasabah(Request $request)
    {
        $request->validate([
            'nik' => 'sometimes|string|regex:/^\d+$/',
            'nama' => 'sometimes|string',
            'alamat' => 'sometimes|string',
            'nomor_wa' => 'sometimes|string',
            'nomor_rekening' => 'sometimes|string',
            'nama_pemilik_rekening' => 'sometimes|string',
            'jenis_rekening' => 'sometimes|string',
        ]);

        $nasabah = Nasabah::where('user_id', $request->get("user_id"))->first();

        if (!$nasabah) {
            return response()->json([
                'status' => false,
                'message' => 'Nasabah tidak ditemukan'
            ], 404);
        }

        // Update fields if provided
        foreach ($request->only([
            'nik', 'nama', 'alamat', 'nomor_wa', 
            'nomor_rekening', 'nama_pemilik_rekening', 
            'jenis_rekening', 
            
        ]) as $key => $value) {
            if ($value !== null) {
                $nasabah->$key = $value;
            }
        }

        $nasabah->save();

        return response()->json([
            'status' => true,
            'message' => 'Profil nasabah berhasil diperbarui',
            'data' => $nasabah
        ], 200);
    }

    public function cekBSUNasabah(Request $request)
    {
        $nasabah = Nasabah::where("user_id", $request->get("user_id"));
        if (!$nasabah->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Nasabah tidak ditemukan'
            ], 404);
        }

        $bsu_id_nasabah = $nasabah->first()->bsu_id;

        $client = new Client([
            'timeout' => 5,
        ]);

        try {
            // Fetch BSU data from the API
            $response = $client->request("GET", "http://145.79.10.111:8003/api/v1/bsu/cek-bsu/{$bsu_id_nasabah}");
            $data_bsu = json_decode($response->getBody(), true);

            return response()->json([
                'status' => true,
                'data' => $data_bsu['data']
            ], 200);
            
        } catch (RequestException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch BSU data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function cekTransaksiNasabah(Request $request)
    {
        $client = new Client([
            'timeout' => 5,
        ]);
        $nasabah = Nasabah::where("user_id", $request->get("user_id"));
        $response = $client->request("GET", "http://145.79.10.111:8003/api/v1/bsu/cek-transaksi-nasabah/".$nasabah->first()->nik, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => $request->get("token")
            ],  
        ]);
        $data_transaksi = json_decode($response->getBody(), true);
        return $data_transaksi;
    }

    public function ajukanPenarikan(Request $request)
    {
        $nasabah = Nasabah::where("user_id", $request->get("user_id"))->first();

        // Check if nasabah exists
        if (!$nasabah) {
            return response()->json([
                'status' => false,
                'message' => 'Nasabah tidak ditemukan'
            ], 404);
        }

        // Check if total_penarikan exceeds saldo
        if ($request->total_penarikan > $nasabah->saldo) {
            return response()->json([
                'status' => false,
                'message' => 'Total penarikan melebihi saldo'
            ], 400);
        } 
        
        $client = new Client([
            "timeout" => 5,
        ]);
        $response = $client->request("POST", "http://145.79.10.111:8003/api/v1/bsu/ajukan-penarikan-nasabah/", [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => $request->get("token"),
            ],
            "json" => [
                "total_penarikan" => $request->total_penarikan,
                "nik" => $nasabah->nik,
                "bsu_id" => $nasabah->bsu_id,
            ]
        ]);

        $response = json_decode($response->getBody());
        return response()->json($response); // Ensure response is formatted as JSON
    }

    public function cekSemuaPengajuanNasabah(Request $request)
    {
        $nasabah = Nasabah::where("user_id", $request->get("user_id"))->first();
        if (!$nasabah) {
            return response()->json([
                'status' => false,
                'message' => 'Nasabah tidak ditemukan'
            ], 404);
        }
        $client = new Client([
            "timeout" => 5,
        ]);
        $response = $client->request("GET", "http://145.79.10.111:8003/api/v1/bsu/cek-ajukan-penarikan-nasabah/" . $nasabah->nik . '/' . $nasabah->bsu_id, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => $request->get("token"),
            ]
        ]);

        $response = json_decode($response->getBody());
        return response()->json($response); // Ensure response is formatted as JSON

    }

    

}