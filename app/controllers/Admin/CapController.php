<?php
namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Services\CapExperimentService;

final class CapController extends Controller
{
    private function guard(): void
    {
        Auth::requireRole('admin');
        if (!cfg('cap_demo')) { http_response_code(404); exit('404'); }
        \App\Core\MongoDatabase::requireDemo();
    }

    public function index(): void
    {
        $this->guard();
        $this->view('admin/cap/index', ['title' => 'Thực nghiệm NoSQL & CAP'], 'admin');
    }

    public function status(): void
    {
        $this->guard(); session_write_close();
        $this->json((new CapExperimentService())->status());
    }

    public function probe(): void
    {
        $this->guard(); Csrf::verify(); session_write_close();
        $this->json((new CapExperimentService())->probe($_POST));
    }

    public function logs(): void
    {
        $this->guard(); session_write_close();
        $this->json((new CapExperimentService())->recent());
    }

    public function export(): void
    {
        $this->guard(); session_write_close();
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="cap-experiments.jsonl"');
        $path = dirname(__DIR__, 3) . '/storage/cap/experiments.jsonl';
        if (is_file($path)) readfile($path);
    }
}
