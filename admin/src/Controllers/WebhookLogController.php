<?php
declare(strict_types=1);

namespace Iqteco\WaAdmin\Controllers;

use Iqteco\WaAdmin\Services\AuthService;
use Iqteco\WaAdmin\Services\Csrf;
use Iqteco\WaAdmin\Services\MongoClient;
use Iqteco\WaAdmin\Services\View;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

final class WebhookLogController
{
    public function __construct(private readonly array $config) {}

    public function index(array $params): void
    {
        (new AuthService($this->config))->requireAuth();

        $idInstance = (string)$params['id'];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        $type = (string)($_GET['type'] ?? '');
        $status = (string)($_GET['status'] ?? '');

        $filter = ['idInstance' => $idInstance];
        if ($type !== '') $filter['type'] = $type;
        if ($status !== '') $filter['status'] = $status;

        $log = MongoClient::db($this->config)->selectCollection('webhook_log');
        $total = $log->countDocuments($filter);
        $items = $log->find($filter, [
            'sort' => ['sentAt' => -1],
            'limit' => $perPage,
            'skip' => ($page - 1) * $perPage,
        ])->toArray();

        $types = $log->distinct('type', ['idInstance' => $idInstance]);
        $statuses = $log->distinct('status', ['idInstance' => $idInstance]);

        // Stats for bulk-retry UI (live outbox state)
        $outbox = MongoClient::db($this->config)->selectCollection('webhook_outbox');
        $pendingCount = $outbox->countDocuments(['idInstance' => $idInstance, 'status' => 'pending']);
        $failedCount = $outbox->countDocuments(['idInstance' => $idInstance, 'status' => 'failed']);

        View::renderLayout('webhook_log', [
            'idInstance' => $idInstance,
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
            'filterType' => $type,
            'filterStatus' => $status,
            'allTypes' => $types,
            'allStatuses' => $statuses,
            'pendingCount' => $pendingCount,
            'failedCount' => $failedCount,
        ]);
    }

    public function show(array $params): void
    {
        (new AuthService($this->config))->requireAuth();
        try {
            $id = new ObjectId($params['logId']);
        } catch (\Throwable) {
            http_response_code(404);
            echo 'invalid log id';
            return;
        }
        $log = MongoClient::db($this->config)->selectCollection('webhook_log')->findOne(['_id' => $id]);
        if (!$log) {
            http_response_code(404);
            echo 'not found';
            return;
        }
        header('Content-Type: application/json');
        echo json_encode($log['payload'] ?? new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function retry(array $params): void
    {
        (new AuthService($this->config))->requireAuth();
        Csrf::requireValid();

        try {
            $id = new ObjectId($params['logId']);
        } catch (\Throwable) {
            http_response_code(404);
            echo 'invalid log id';
            return;
        }
        $log = MongoClient::db($this->config)->selectCollection('webhook_log')->findOne(['_id' => $id]);
        if (!$log) {
            http_response_code(404);
            echo 'not found';
            return;
        }

        MongoClient::db($this->config)->selectCollection('webhook_outbox')->insertOne([
            'idInstance' => $log['idInstance'],
            'typeWebhook' => $log['type'],
            'payload' => $log['payload'],
            'status' => 'pending',
            'attempts' => 0,
            'nextAttemptAt' => new UTCDateTime(),
            'createdAt' => new UTCDateTime(),
            'manualRetryFromLogId' => (string)$id,
        ]);

        header('Location: /instances/' . $log['idInstance'] . '/webhooks');
    }

    /**
     * Bulk replay: для всех failed webhook_outbox записей этого инстанса
     * создаёт новые pending копии с attempts=0, nextAttemptAt=now.
     * Старые failed остаются в БД для аудита.
     */
    public function retryFailedBulk(array $params): void
    {
        (new AuthService($this->config))->requireAuth();
        Csrf::requireValid();

        $idInstance = (string)$params['id'];
        $outbox = MongoClient::db($this->config)->selectCollection('webhook_outbox');

        $cursor = $outbox->find(
            ['idInstance' => $idInstance, 'status' => 'failed'],
            ['limit' => 1000],
        );
        $now = new UTCDateTime();
        $count = 0;
        $docs = [];
        foreach ($cursor as $f) {
            $docs[] = [
                'idInstance' => $idInstance,
                'typeWebhook' => $f['typeWebhook'] ?? 'unknown',
                'payload' => $f['payload'] ?? new \stdClass(),
                'status' => 'pending',
                'attempts' => 0,
                'nextAttemptAt' => $now,
                'createdAt' => $now,
                'manualRetryFromOutboxId' => (string)$f['_id'],
            ];
            $count++;
            if (count($docs) >= 500) {
                $outbox->insertMany($docs);
                $docs = [];
            }
        }
        if ($docs) $outbox->insertMany($docs);

        header('Location: /instances/' . $idInstance . '/webhooks?retried=' . $count);
    }
}
