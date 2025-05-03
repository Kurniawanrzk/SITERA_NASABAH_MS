<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\
{
    NasabahController,
    BSUController
};

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix("v1/nasabah")->group(function(){
    Route::post("tambah-nasabah", [NasabahController::class, "buatAkunNasabah"])->middleware("checkifbsu");
    Route::post("tambah-nasabah-csv", [NasabahController::class, "buatAkunNasabahCSV"])->middleware("checkifbsu");
    Route::get("cek-profil", [NasabahController::class, "cekProfileNasabah"])->middleware("checkifnasabah");
    Route::get("cek-kontribusi-nasabah-bsu/", [NasabahController::class, "cekSemuaKontribusiNasabahBerdasarkanBSU"])->middleware("checkifbsu");
    Route::get("cek-nasabah-bsu/", [NasabahController::class, "cekSemuaNasabahBerdasarkanBSU"])->middleware("checkifbsu");
    Route::put("tambah-saldo-total-sampah-nasabah/", [BSUController::class, "isiSaldoTotalSampahNasabah"])->middleware("checkifbsu");
    Route::post("ubah-saldo", [BSUController::class, "ubahSaldoNasabah"])->middleware("checkifbsu");

    Route::get("cek-nasabah-user-id/{user_id}", [BSUController::class, "cekNasabahDariUserId"])->middleware("checkifnasabah");
    Route::post("ajuan-penarikan", [NasabahController::class, "ajukanPenarikan"])->middleware("checkifnasabah");
    Route::get("cek-ajuan-penarikan", [NasabahController::class, "cekSemuaPengajuanNasabah"])->middleware("checkifnasabah");
    // Special Cases BSU udah dicek di microservices BSU Management
    Route::get("cek-nasabah/{nik}", [NasabahController::class, "cekNasabahDariNIK"]);
    Route::get("cek-user/{user_id}", [NasabahController::class, "cekUser"]);


    Route::put("edit-profil", [NasabahController::class, "editProfilNasabah"])->middleware("checkifnasabah");
    Route::get("cek-bsu-nasabah", [NasabahController::class, "cekBSUNasabah"])->middleware("checkifnasabah");

    Route::get('cek-transaksi', [NasabahController::class, 'cekTransaksiNasabah'])->middleware("checkifnasabah");
});
