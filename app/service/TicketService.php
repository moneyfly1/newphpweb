<?php
declare(strict_types=1);

namespace app\service;

use app\model\Ticket;
use app\model\TicketReply;
use app\model\User;
use app\service\NotificationService;

class TicketService
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AppConfigService $config
    ) {}

    private function currentUserId(): int
    {
        return (int) ($this->auth->user()['id'] ?? 0);
    }

    /**
     * User ticket list (with N+1 fix - use eager loading)
     */
    public function tickets(): array
    {
        $ticketIds = Ticket::where('user_id', $this->currentUserId())
            ->order('id', 'desc')
            ->column('id');

        // Batch load all replies for these tickets to avoid N+1
        $allReplies = [];
        if (!empty($ticketIds)) {
            $replies = TicketReply::whereIn('ticket_id', $ticketIds)
                ->order('id', 'desc')
                ->select();
            foreach ($replies as $reply) {
                $allReplies[(int) $reply->ticket_id][] = [
                    'replier_type' => (string) $reply->replier_type,
                    'content'      => (string) $reply->content,
                    'created_at'   => (string) $reply->created_at,
                ];
            }
        }

        $tickets = Ticket::where('user_id', $this->currentUserId())
            ->order('id', 'desc')
            ->select();

        return [
            'tickets' => $tickets->map(function (Ticket $ticket) use ($allReplies): array {
                $replies = $allReplies[(int) $ticket->id] ?? [];
                return [
                    'id'             => $ticket->id,
                    'no'             => $ticket->no,
                    'ticket_no'      => $ticket->no,
                    'title'          => $ticket->subject,
                    'subject'        => $ticket->subject,
                    'type'           => 'other',
                    'status'         => $ticket->status,
                    'status_label'   => $this->ticketStatusLabel($ticket->status),
                    'priority'       => $ticket->priority,
                    'priority_label' => $this->ticketPriorityLabel($ticket->priority),
                    'updated_at'     => (string) $ticket->updated_at,
                    'created_at'     => (string) $ticket->created_at,
                    'excerpt'        => mb_substr($ticket->content, 0, 56) . '...',
                    'content'        => (string) $ticket->content,
                    'has_unread'     => $ticket->status === 'replied',
                    'unread_replies' => $ticket->status === 'replied' ? max(1, count($replies)) : 0,
                    'replies'        => $replies,
                ];
            })->all(),
        ];
    }

    public function createTicket(array $payload): array
    {
        $subject = trim((string) ($payload['subject'] ?? ''));
        $content = trim((string) ($payload['content'] ?? ''));
        if ($subject === '' || $content === '') {
            throw new \RuntimeException('标题和内容不能为空。');
        }
        if (mb_strlen($subject) > 100) {
            throw new \RuntimeException('标题不能超过100个字符。');
        }
        if (mb_strlen($content) > 5000) {
            throw new \RuntimeException('内容不能超过5000个字符。');
        }

        $no = 'TK' . date('ymd') . strtoupper(substr(uniqid(), -6));
        Ticket::create([
            'no'      => $no,
            'user_id' => $this->currentUserId(),
            'subject' => $subject,
            'content' => $content,
            'status'  => 'open',
        ]);

        NotificationService::notifyAdmins(NotificationService::TICKET_CREATED, ['工单号' => $no, '标题' => $subject]);
        return ['no' => $no];
    }

    public function adminTickets(array $filters = []): array
    {
        $filters = $this->normalizeListFilters($filters);
        $query = Ticket::order('id', 'desc');

        if (!empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $userIds = User::where('email', 'like', '%' . $keyword . '%')
                ->whereOr('nickname', 'like', '%' . $keyword . '%')
                ->column('id');

            $query->where(function ($q) use ($keyword, $userIds) {
                $q->where('no', 'like', '%' . $keyword . '%')
                  ->whereOr('subject', 'like', '%' . $keyword . '%');
                if (is_numeric($keyword)) {
                    $q->whereOr('user_id', (int) $keyword);
                }
                if (!empty($userIds)) {
                    $q->whereOr('user_id', 'in', $userIds);
                }
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $query->where('priority', $filters['type']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        return $query->select()->map(fn (Ticket $ticket): array => [
            'id'         => $ticket->id,
            'no'         => $ticket->no,
            'user'       => $this->userName((int) $ticket->user_id),
            'user_id'    => (int) $ticket->user_id,
            'subject'    => $ticket->subject,
            'status'     => $this->ticketStatusLabel((string) $ticket->status),
            'status_key' => (string) $ticket->status,
            'priority'   => $this->ticketPriorityLabel((string) $ticket->priority),
            'priority_key' => (string) $ticket->priority,
            'updated_at' => (string) $ticket->updated_at,
        ])->all();
    }

    public function replyTicket(string $ticketNo, string $content): array
    {
        $ticket = Ticket::where('no', $ticketNo)->find();
        if (!$ticket) {
            throw new \RuntimeException('工单不存在。');
        }

        TicketReply::create([
            'ticket_id'    => $ticket->id,
            'replier_type' => 'admin',
            'replier_id'   => $this->currentUserId(),
            'content'      => trim($content),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $ticket->status = 'replied';
        $ticket->admin_id = $this->currentUserId();
        $ticket->save();

        NotificationService::notify((int) $ticket->user_id, NotificationService::TICKET_REPLIED, [
            '工单号' => $ticketNo, '标题' => $ticket->subject, '回复内容' => mb_substr(trim($content), 0, 100),
        ]);
        return ['status' => 'replied'];
    }

    private function ticketStatusLabel(string $status): string
    {
        return match ($status) {
            'open'        => '待处理',
            'in_progress' => '处理中',
            'replied'     => '已回复',
            'closed'      => '已关闭',
            default       => $status,
        };
    }

    private function ticketPriorityLabel(string $priority): string
    {
        return match ($priority) {
            'low'    => '低',
            'normal' => '普通',
            'high'   => '高',
            'urgent' => '紧急',
            default  => $priority,
        };
    }

    private function userName(int $userId): string
    {
        $user = User::find($userId);
        return $user ? ((string) ($user->nickname ?: $user->email)) : '未知用户';
    }

    private function normalizeListFilters(array $filters): array
    {
        return [
            'keyword'   => trim((string) ($filters['keyword'] ?? '')),
            'status'    => trim((string) ($filters['status'] ?? '')),
            'type'      => trim((string) ($filters['type'] ?? '')),
            'date_from' => trim((string) ($filters['date_from'] ?? '')),
            'date_to'   => trim((string) ($filters['date_to'] ?? '')),
        ];
    }
}
