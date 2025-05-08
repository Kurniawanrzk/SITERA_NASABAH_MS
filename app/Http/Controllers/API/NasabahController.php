<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;    
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Models\Nasabah;
use  Carbon\Carbon;

use Illuminate\Support\Facades\{Hash}; 

class NasabahController extends Controller
{
    public function getBatchNasabah(Request $request)
    {
        // Parse daftar NIK dari string yang dipisahkan koma
        $nikList = explode(',', $request->nik_list);
        
        // Hapus whitespace jika ada
        $nikList = array_map('trim', $nikList);
        
        // Filter NIK kosong jika ada
        $nikList = array_filter($nikList, function($nik) {
            return !empty($nik);
        });

        if (empty($nikList)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Daftar NIK tidak valid',
                'data' => []
            ], 400);
        }

        try {
            // Ambil data nasabah berdasarkan daftar NIK
            $nasabahs = Nasabah::whereIn('nik', $nikList)->get();
            
            // Tambahkan field untuk flag nasabah yang ditemukan
            $result = [];
            foreach ($nikList as $nik) {
                $nasabah = $nasabahs->firstWhere('nik', $nik);
                
                if ($nasabah) {
                    // Sertakan hanya field yang diperlukan untuk respons
                    $result[] = [
                        'nik' => $nasabah->nik,
                        'nama' => $nasabah->nama,
                        'alamat' => $nasabah->alamat,
                        'nomor_wa' => $nasabah->nomor_wa,
                        'reward_level' => $nasabah->reward_level,
                        'total_sampah' => $nasabah->total_sampah,
                        'saldo' => $nasabah->saldo,
                        'bsu_id' => $nasabah->bsu_id,
                        'found' => true
                    ];
                } else {
                    // Sertakan NIK yang tidak ditemukan dengan status not found
                    $result[] = [
                        'nik' => $nik,
                        'found' => false
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Data nasabah berhasil diambil',
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data nasabah',
                'error_detail' => $e->getMessage()
            ], 500);
        }
    }
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
            'nama_bank' => 'sometimes|string',
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
            'jenis_rekening', 'nama_bank'
            
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

    public function cekKontribusiNasabah(Request $request)
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
        $total_transaksi = count($data_transaksi['data']);
        
        // Mengumpulkan data sampah dari setiap transaksi
        $presentaseSampah = [];
        foreach($data_transaksi['data'] as $key => $value) {
            foreach($value['detail_transaksi'] as $key2 => $value2) {
                $presentaseSampah[$key]['sampah'][$key2]['tipe'] = $value2['sampah']['tipe'];
                $presentaseSampah[$key]['sampah'][$key2]['berat'] = $value2['berat'];
            }
        }
        
        // Hitung total berat per tipe sampah dan total keseluruhan
        $totalWeightByType = [];
        $totalWeight = 0;
        
        foreach ($presentaseSampah as $transaction) {
            if (isset($transaction['sampah']) && is_array($transaction['sampah'])) {
                foreach ($transaction['sampah'] as $waste) {
                    $type = $waste['tipe'];
                    $weight = (float) $waste['berat'];
                    
                    // Tambahkan ke total berat per tipe
                    if (!isset($totalWeightByType[$type])) {
                        $totalWeightByType[$type] = 0;
                    }
                    $totalWeightByType[$type] += $weight;
                    
                    // Tambahkan ke total berat keseluruhan
                    $totalWeight += $weight;
                }
            }
        }
        
        // Hitung persentase untuk setiap tipe sampah
        $percentages = [];
        foreach ($totalWeightByType as $type => $weight) {
            $percentages[$type] = [
                'total_berat' => $weight,
                'persentase' => ($totalWeight > 0) ? round(($weight / $totalWeight) * 100, 2) : 0
            ];
        }
        
        // Siapkan hasil akhir
        $result = [
            'data_transaksi' => $presentaseSampah,
            'total_transaksi' => $total_transaksi,
            'total_berat_keseluruhan' => $totalWeight,
            'persentase_per_tipe' => $percentages
        ];
        
        return response()->json($result);
    }

public function cekKontribusiPerjenjang(Request $request)
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
    
    // Mengolah data untuk grafik mingguan
    $mingguan = $this->processDataForWeeklyChart($data_transaksi['data']);
    
    // Menghitung persentase per tipe sampah
    $persentaseTipe = $this->calculateWastePercentages($data_transaksi['data']);

    $bulanan = $this->processDataForMonthlyChart($data_transaksi['data']);
    
    return response()->json([
        'status' => true,
        'data' => [
            'mingguan' => $mingguan,
            'bulanan' => $bulanan,
            'persentase_tipe' => $persentaseTipe['data'],
            'total_berat_keseluruhan' => $persentaseTipe['total_berat']
        ]
    ], 200);
}
private function processDataForWeeklyChart($transaksi_data)
{
    // Membuat array untuk menyimpan data per minggu
    $weeks = [];
    $now = Carbon::now();
    
    // Inisialisasi 4 minggu terakhir
    for ($i = 0; $i < 4; $i++) {
        $weekStart = $now->copy()->subWeeks($i)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();
        
        $weeks[$i + 1] = [
            'label' => 'Minggu ' . ($i + 1),
            'berat_sampah' => 0,
            'poin' => 0,
            'start_date' => $weekStart->format('Y-m-d'),
            'end_date' => $weekEnd->format('Y-m-d'),
        ];
    }
    
    // Urutkan transaksi berdasarkan tanggal
    foreach ($transaksi_data as $transaksi) {
        $tanggal = Carbon::parse($transaksi['created_at']);
        
        // Cari minggu yang sesuai
        foreach ($weeks as $week_number => $week) {
            $weekStart = Carbon::parse($week['start_date']);
            $weekEnd = Carbon::parse($week['end_date']);
            
            if ($tanggal->between($weekStart, $weekEnd)) {
                // Jumlahkan berat sampah
                foreach ($transaksi['detail_transaksi'] as $detail) {
                    $weeks[$week_number]['berat_sampah'] += (float) $detail['berat'];
                }
                
                // Jumlahkan poin - check if 'poin' key exists
                if (isset($transaksi['poin'])) {
                    $weeks[$week_number]['poin'] += (int) $transaksi['poin'];
                }
                break;
            }
        }
    }
    
    // Reverse array untuk tampilkan minggu terlama di awal
    $weeks = array_reverse($weeks);
    
    // Format data untuk chart
    $result = [
        'labels' => [],
        'berat_sampah' => [],
        'poin' => [],
        'table_data' => []
    ];
    
    $total_berat = 0;
    $total_poin = 0;
    
    foreach ($weeks as $week) {
        $result['labels'][] = $week['label'];
        $result['berat_sampah'][] = $week['berat_sampah'];
        $result['poin'][] = $week['poin'];
        
        $result['table_data'][] = [
            'periode' => $week['label'],
            'berat_sampah' => number_format($week['berat_sampah'], 1),
            'poin' => $week['poin']
        ];
        
        $total_berat += $week['berat_sampah'];
        $total_poin += $week['poin'];
    }
    
    $result['total_berat'] = number_format($total_berat, 1);
    $result['total_poin'] = $total_poin;
    
    return $result;
}

private function processDataForMonthlyChart($transaksi_data)
{
    // Membuat array untuk menyimpan data per bulan
    $months = [];
    $now = Carbon::now();
    
    // Jumlah bulan yang ingin ditampilkan
    $numMonths = 6; // Misalnya 6 bulan terakhir
    
    // Inisialisasi bulan-bulan terakhir
    for ($i = 0; $i < $numMonths; $i++) {
        $monthStart = $now->copy()->subMonths($i)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        
        // Gunakan nama bulan dalam bahasa Indonesia
        $monthNames = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];
        
        $monthName = $monthNames[$monthStart->month - 1] . ' ' . $monthStart->year;
        
        $months[$i + 1] = [
            'label' => $monthName,
            'berat_sampah' => 0,
            'poin' => 0,
            'start_date' => $monthStart->format('Y-m-d'),
            'end_date' => $monthEnd->format('Y-m-d'),
        ];
    }
    
    // Proses transaksi berdasarkan tanggal
    foreach ($transaksi_data as $transaksi) {
        $tanggal = Carbon::parse($transaksi['created_at']);
        
        // Cari bulan yang sesuai
        foreach ($months as $month_number => $month) {
            $monthStart = Carbon::parse($month['start_date']);
            $monthEnd = Carbon::parse($month['end_date']);
            
            if ($tanggal->between($monthStart, $monthEnd)) {
                // Jumlahkan berat sampah
                foreach ($transaksi['detail_transaksi'] as $detail) {
                    $months[$month_number]['berat_sampah'] += (float) $detail['berat'];
                }
                
                // Jumlahkan poin - check if 'poin' key exists
                if (isset($transaksi['poin'])) {
                    $months[$month_number]['poin'] += (int) $transaksi['poin'];
                }
                break;
            }
        }
    }
    
    // Reverse array untuk tampilkan bulan terlama di awal
    $months = array_reverse($months);
    
    // Format data untuk chart
    $result = [
        'labels' => [],
        'berat_sampah' => [],
        'poin' => [],
        'table_data' => []
    ];
    
    $total_berat = 0;
    $total_poin = 0;
    
    foreach ($months as $month) {
        $result['labels'][] = $month['label'];
        $result['berat_sampah'][] = $month['berat_sampah'];
        $result['poin'][] = $month['poin'];
        
        $result['table_data'][] = [
            'periode' => $month['label'],
            'berat_sampah' => number_format($month['berat_sampah'], 1),
            'poin' => $month['poin']
        ];
        
        $total_berat += $month['berat_sampah'];
        $total_poin += $month['poin'];
    }
    
    $result['total_berat'] = number_format($total_berat, 1);
    $result['total_poin'] = $total_poin;
    
    return $result;
}

private function calculateWastePercentages($transaksi_data)
{
    // Menghitung total berat per tipe sampah
    $totalWeightByType = [];
    $totalWeight = 0;
    
    foreach ($transaksi_data as $transaksi) {
        foreach ($transaksi['detail_transaksi'] as $detail) {
            $type = $detail['sampah']['tipe'];
            $weight = (float) $detail['berat'];
            
            if (!isset($totalWeightByType[$type])) {
                $totalWeightByType[$type] = 0;
            }
            $totalWeightByType[$type] += $weight;
            $totalWeight += $weight;
        }
    }
    
    // Hitung persentase
    $percentages = [];
    foreach ($totalWeightByType as $type => $weight) {
        $percentages[$type] = [
            'tipe' => $type,
            'berat' => $weight,
            'persentase' => ($totalWeight > 0) ? round(($weight / $totalWeight) * 100, 2) : 0
        ];
    }
    
    return [
        'data' => array_values($percentages),
        'total_berat' => $totalWeight
    ];
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