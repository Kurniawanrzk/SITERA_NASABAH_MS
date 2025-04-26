<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class Nasabah extends Model
{
    use HasFactory, HasUuid;

    protected $table = "nasabah";
    public $incrementing = false;
    protected $keyType = 'string'; 
    protected $fillable = [
        'user_id',
        'nik',
        'nama',
        'alamat',
        'nomor_wa',
        'nomor_rekening',
        'nama_pemilik_rekening',
        'jenis_rekening',
        'reward_level',
        'total_sampah',
        'saldo',
        'bsu_id',
    ];

    protected $hidden = [

    ];
}
