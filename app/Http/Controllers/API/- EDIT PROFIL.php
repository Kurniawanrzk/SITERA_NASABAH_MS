- EDIT PROFIL 
http://127.0.0.1:7000/api/v1/nasabah/edit-profil
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
- CEK PROFIL 
http://127.0.0.1:7000/api/v1/nasabah/cek-profil
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
- CEK SAMPAH TRANSAKSI NASABAH 
http://127.0.0.1:7000/api/v1/nasabah/cek-transaksi
public function cekTransaksiNasabah(Request $request)
    {
        $client = new Client([
            'timeout' => 5,
        ]);
        $nasabah = Nasabah::where("user_id", $request->get("user_id"));
        $response = $client->request("GET", "http://127.0.0.1:3000/api/v1/bsu/cek-transaksi-nasabah/".$nasabah->first()->nik, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => $request->get("token")
            ],  
        ]);
        $data_transaksi = json_decode($response->getBody(), true);
        return $data_transaksi;
    }
- PENGAJUAN PENARIKAN UANG 
http://127.0.0.1:7000/api/v1/nasabah/ajuan-penarikan
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
        $response = $client->request("POST", "http://127.0.0.1:3000/api/v1/bsu/ajukan-penarikan-nasabah/", [
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

    - CEK SEMUA PENGAJUAN 
    http://127.0.0.1:7000/api/v1/nasabah/cek-ajuan-penarikan
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
        $response = $client->request("GET", "http://127.0.0.1:3000/api/v1/bsu/cek-ajukan-penarikan-nasabah/" . $nasabah->nik . '/' . $nasabah->bsu_id, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => $request->get("token"),
            ]
        ]);

        $response = json_decode($response->getBody());
        return response()->json($response); // Ensure response is formatted as JSON

    }