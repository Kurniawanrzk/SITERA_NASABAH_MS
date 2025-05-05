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
        
        // Read file content and remove BOM if present
        $content = file_get_contents($path);
        $content = str_replace("\xEF\xBB\xBF", '', $content); // Remove UTF-8 BOM
        
        // Parse CSV content
        $rows = str_getcsv($content, "\n");
        $data = [];
        foreach($rows as $row) {
            $data[] = str_getcsv($row);
        }
        
        $success = 0;
        $failed = 0;
        $failedRows = [];
        
        $client_auth_sitera = new Client([
            "timeout" => 5
        ]);
    
        foreach ($data as $index => $row) {
            if($index === 0) {
                continue; // Skip header row
            }
            // Skip empty rows
            if (empty($row) || !isset($row[0]) || trim($row[0]) === '') {
                continue;
            }
            
            $row = array_map(function($value) {
                return trim(str_replace(';;;', '', $value));
            }, $row);
            
            // Skip header row if exists
            if ($index === 0 && (strtolower(trim($row[0])) === 'email' || strpos(strtolower($row[0]), 'email') !== false)) {
                continue;
            }
            
            try {
                // Check if required indices exist
                if (!isset($row[0]) || !isset($row[1])) {
                    throw new \Exception("Missing required fields (email or password)");
                }
                
                $email = trim($row[0]);
                $password = trim($row[1]);
                
                if (empty($email) || empty($password)) {
                    throw new \Exception("Email or password cannot be empty");
                }
                
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
                
                $response_body = json_decode($response_auth_sitera->getBody());
                
                // Add error handling for API response
                if (!isset($response_body->data) || !isset($response_body->data->user)) {
                    throw new \Exception("Invalid API response structure");
                }
                
                $data_response = $response_body->data->user;
                
                // Create Nasabah record
                $user_nasabah = new Nasabah;
                $user_nasabah->user_id = $data_response->id;
                $user_nasabah->bsu_id = $request->get("bsu_id");
                $user_nasabah->nama = isset($row[2]) ? trim($row[2]) : null; // Assuming name is in column 3
                $user_nasabah->nik = isset($row[3]) ? trim($row[3]) : null;  // Assuming NIK is in column 4
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
            $user_nasabah->nama = $request->nama;
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
        // Ambil data nasabah dari database
        $nasabah = Nasabah::where("bsu_id", $request->get("bsu_id"))->get();
    
        return response()->json([
            'status' => true,
            'data' => $nasabah,
        ], 200);
    }

    public function cekTabunganNasabah(Request $request)
    {
        // Define pagination parameters
        $perPage = $request->get('per_page', 10); // Default 10 items per page
        $page = $request->get('page', 1); // Default page 1
        
        // Ambil data nasabah dari database
        $nasabah = Nasabah::where("bsu_id", $request->get("bsu_id"))->get();
        
        // Gunakan CURL langsung daripada Guzzle
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => 'http://145.79.10.111:8003/api/v1/bsu/cek-semua-transaksi-bsu',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: ' . $request->get("token")
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        
        curl_close($ch);
        
        \Log::info('CURL Info: ' . json_encode($info));
        
        if ($error) {
            \Log::error('CURL Error: ' . $error);
            return response()->json([
                'status' => false,
                'message' => 'Gagal terhubung ke API eksternal',
                'error' => $error,
                'info' => $info
            ], 500);
        }
        
        $body = json_decode($response, true);
        
        $transaksiData = $body['data'] ?? [];
        
        // Gabungkan data nasabah dan transaksi berdasarkan NIK
        $nasabahGabungan = $nasabah->map(function ($n) use ($transaksiData) {
            $nik = $n->nik;
    
            // Filter transaksi berdasarkan NIK
            $transaksiNasabah = collect($transaksiData)->where('nik', $nik);
    
            // Hitung frekuensi kontribusi
            $frekuensi = $transaksiNasabah->count();
    
            // Kategorisasi frekuensi
            if ($frekuensi >= 10) {
                $kategoriFrekuensi = 'Tinggi';
            } elseif ($frekuensi >= 5) {
                $kategoriFrekuensi = 'Sedang';
            } else {
                $kategoriFrekuensi = 'Rendah';
            }
    
            // Ambil kontribusi terakhir
            $kontribusiTerakhir = $transaksiNasabah->sortByDesc('waktu_transaksi')->first()['waktu_transaksi'] ?? null;
    
            // Hitung total transaksi
            $totalTransaksi = $transaksiNasabah->sum(function ($t) {
                return (float) $t['total_harga'];
            });
    
            // Hitung total berat dari detail_transaksi
            $totalBerat = $transaksiNasabah->flatMap(function ($t) {
                return $t['detail_transaksi'];
            })->sum(function ($d) {
                return (float) $d['berat'];
            });
    
            // Jenis sampah unik
            $jenisSampah = $transaksiNasabah->flatMap(function ($t) {
                return $t['detail_transaksi'];
            })->pluck('sampah.nama')->unique()->values();
    
            return [
                'nasabah' => $n,
                'frekuensi_kontribusi' => $frekuensi,
                'kategori_frekuensi' => $kategoriFrekuensi,
                'kontribusi_terakhir' => $kontribusiTerakhir,
                'total_transaksi' => $totalTransaksi,
                'total_berat' => $totalBerat,
                'jenis_sampah' => $jenisSampah,
            ];
        });
        
        // Apply sorting if requested
        if ($request->has('sort_by')) {
            $sortBy = $request->get('sort_by');
            $sortDirection = $request->get('sort_direction', 'asc');
            
            if (in_array($sortBy, ['frekuensi_kontribusi', 'total_transaksi', 'total_berat'])) {
                $nasabahGabungan = $nasabahGabungan->sortBy([
                    [$sortBy, $sortDirection === 'desc' ? 'desc' : 'asc']
                ]);
            }
        }
        
        // Get total count
        $totalItems = $nasabahGabungan->count();
        $totalPages = ceil($totalItems / $perPage);
        
        // Paginate manually
        $paginatedData = $nasabahGabungan->forPage($page, $perPage)->values();
        
        return response()->json([
            'status' => true,
            'data' => $paginatedData,
            'pagination' => [
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'has_next_page' => $page < $totalPages,
                'has_previous_page' => $page > 1
            ]
        ], 200);
    }
    public function cekSemuaKontribusiNasabahBerdasarkanBSU(Request $request)
    {
        // Define pagination parameters
        $perPage = $request->get('per_page', 10); // Default 10 items per page
        $page = $request->get('page', 1); // Default page 1
        
        // Ambil data nasabah dari database
        $nasabah = Nasabah::where("bsu_id", $request->get("bsu_id"))->get();
        
        // Gunakan CURL langsung daripada Guzzle
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => 'http://145.79.10.111:8003/api/v1/bsu/cek-semua-transaksi-bsu',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: ' . $request->get("token")
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        
        curl_close($ch);
        
        \Log::info('CURL Info: ' . json_encode($info));
        
        if ($error) {
            \Log::error('CURL Error: ' . $error);
            return response()->json([
                'status' => false,
                'message' => 'Gagal terhubung ke API eksternal',
                'error' => $error,
                'info' => $info
            ], 500);
        }
        
        $body = json_decode($response, true);
        
        $transaksiData = $body['data'] ?? [];
        
        // Gabungkan data nasabah dan transaksi berdasarkan NIK
        $nasabahGabungan = $nasabah->map(function ($n) use ($transaksiData) {
            $nik = $n->nik;
    
            // Filter transaksi berdasarkan NIK
            $transaksiNasabah = collect($transaksiData)->where('nik', $nik);
    
            // Hitung frekuensi kontribusi
            $frekuensi = $transaksiNasabah->count();
    
            // Kategorisasi frekuensi
            if ($frekuensi >= 10) {
                $kategoriFrekuensi = 'Tinggi';
            } elseif ($frekuensi >= 5) {
                $kategoriFrekuensi = 'Sedang';
            } else {
                $kategoriFrekuensi = 'Rendah';
            }
    
            // Ambil kontribusi terakhir
            $kontribusiTerakhir = $transaksiNasabah->sortByDesc('waktu_transaksi')->first()['waktu_transaksi'] ?? null;
    
            // Hitung total transaksi
            $totalTransaksi = $transaksiNasabah->sum(function ($t) {
                return (float) $t['total_harga'];
            });
    
            // Hitung total berat dari detail_transaksi
            $totalBerat = $transaksiNasabah->flatMap(function ($t) {
                return $t['detail_transaksi'];
            })->sum(function ($d) {
                return (float) $d['berat'];
            });
    
            // Jenis sampah unik
            $jenisSampah = $transaksiNasabah->flatMap(function ($t) {
                return $t['detail_transaksi'];
            })->pluck('sampah.nama')->unique()->values();
    
            return [
                'nasabah' => $n,
                'frekuensi_kontribusi' => $frekuensi,
                'kategori_frekuensi' => $kategoriFrekuensi,
                'kontribusi_terakhir' => $kontribusiTerakhir,
                'total_transaksi' => $totalTransaksi,
                'total_berat' => $totalBerat,
                'jenis_sampah' => $jenisSampah,
            ];
        });
        
        // Apply sorting if requested
        if ($request->has('sort_by')) {
            $sortBy = $request->get('sort_by');
            $sortDirection = $request->get('sort_direction', 'asc');
            
            if (in_array($sortBy, ['frekuensi_kontribusi', 'total_transaksi', 'total_berat'])) {
                $nasabahGabungan = $nasabahGabungan->sortBy([
                    [$sortBy, $sortDirection === 'desc' ? 'desc' : 'asc']
                ]);
            }
        }
        
        // Get total count
        $totalItems = $nasabahGabungan->count();
        $totalPages = ceil($totalItems / $perPage);
        
        // Paginate manually
        $paginatedData = $nasabahGabungan->forPage($page, $perPage)->values();
        
        return response()->json([
            'status' => true,
            'data' => $paginatedData,
            'pagination' => [
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'has_next_page' => $page < $totalPages,
                'has_previous_page' => $page > 1
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
        return response()->json([
            'status' => true,
            'data' => $data_transaksi['data'],
            'nasabah' => $nasabah->first()
        ], 200);
 
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