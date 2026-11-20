<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Support\ClientIp;
use Illuminate\Http\Request;

/**
 * 管理画面での操作を admin_audit_logs に残す（誰が・いつ・何に・何をしたか）。
 */
class AuditLogger
{
    public function __construct(private Request $request) {}

    public function log(?Admin $admin, string $action, ?string $targetType = null, ?int $targetId = null, array $payload = []): void
    {
        AdminAuditLog::create([
            'admin_id' => $admin?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $payload ?: null,
            'ip_address' => ClientIp::forLog($this->request),
        ]);
    }
}
