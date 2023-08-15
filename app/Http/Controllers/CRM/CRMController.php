<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Traits\OM_bot;
use Illuminate\Http\Request;
use App\Http\Traits\BX;
use Illuminate\Support\Facades\Log;

class CRMController extends Controller
{
    use BX;

    public function index()
    {
        $this->setDataE($_REQUEST); // получает авторизацию битрикса
        $this->call('user.current'); // получает конкретного пользователя по авторизации
    }

    public function install(Request $request)
    {
        $this->installApp();
    }
}
