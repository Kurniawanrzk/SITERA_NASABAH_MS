<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Models\Nasabah;
class BSUController extends Controller
{

    protected $client;
    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 5, // Timeout 5 detik untuk menghindari request yang terlalu lama
        ]);    
    
    }

    public function isiSaldoTotalSampahNasabah(Request $request)
    {
        $request->validate([
            "nik" => "required|string",
            "saldo" => "required",
            "total_sampah" => "required"
        ]);
        
        $nasabah = Nasabah::where("nik", $request->nik);

        if($nasabah->exists())
        {
            $nasabah->update([
                "saldo" =>  $nasabah->first()->saldo + $request->saldo,
                "total_sampah" => $nasabah->first()->total_sampah + $request->total_sampah
            ]);

            if($nasabah)
            {
                return response()
                ->json([
                    "status" => true,
                    "message" => "saldo dan total sampah berhasil ditambahkan!"
                ], 200);
            } else {
                return response()
                ->json([
                    "status" => false,
                    "message" => "saldo dan total sampah gagal ditambahkan!"
                ], 401);
            }

        }
    }

    public function ubahSaldoNasabah(Request $request)
    {
        $request->validate([
            "nik" => "required|string",
            "saldo" => "required",
        ]);

        $nasabah = Nasabah::where("nik", $request->nik);

        if($nasabah->exists())
        {
            $nasabah->update([
                "saldo" =>  $nasabah->first()->saldo - $request->saldo,
            ]);

            if($nasabah)
            {
                return response()
                ->json([
                    "status" => true,
                    "message" => "saldo berhasil ditambahkan!"
                ], 200);
            } else {
                return response()
                ->json([
                    "status" => false,
                    "message" => "saldo gagal ditambahkan!"
                ], 401);
            }

        }
    }

    public function cekNasabahDariUserId($user_id)
    {
        return response()
        ->json(Nasabah::where("user_id", $user_id)->first(), 200);
    }

}
