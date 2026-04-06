<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;

class TicketController extends BaseController
{
    public function index()
    {
        $filters = [];
        foreach (['keyword', 'status', 'type', 'date_from', 'date_to'] as $field) {
            if (($value = $this->request->get($field, '')) !== '') {
                $filters[$field] = $value;
            }
        }

        $tickets = $this->panel->adminTickets($filters);
        $ticketSummary = [
            'total' => count($tickets),
            'open' => count(array_filter($tickets, fn ($ticket) => ($ticket['status_key'] ?? '') === 'open')),
            'replied' => count(array_filter($tickets, fn ($ticket) => ($ticket['status_key'] ?? '') === 'replied')),
            'closed' => count(array_filter($tickets, fn ($ticket) => ($ticket['status_key'] ?? '') === 'closed')),
        ];

        return $this->render('admin/tickets', [
            'navKey'       => 'admin-tickets',
            'pageTitle'    => '工单中心',
            'pageHeadline' => '客服处理台',
            'pageBlurb'    => '统一搜索、状态筛选、优先级筛选和批量处理。',
            'tickets'      => $tickets,
            'filters'      => $filters,
            'ticketSummary'=> $ticketSummary,
        ]);
    }
}
