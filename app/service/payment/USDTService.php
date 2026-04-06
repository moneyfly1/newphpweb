<?php
declare (strict_types = 1);

namespace app\service\payment;

use think\facade\Log;

/**
 * USDT (Tether USD) 加密货币支付网关
 * 支持 USDT-TRC20 (Tron) 和 USDT-ERC20 (Ethereum)
 */
class USDTService extends PaymentGateway
{
    protected string $code = 'usdt';
    protected string $name = 'USDT 稳定币支付';
    protected array $features = [
        'crypto' => true,        // 加密货币
        'manual' => true,        // 需要手工确认
        'refund' => false,       // 不支持自动退款
        'recurring' => false,    // 不支持订阅
    ];

    public function validateConfig(): bool
    {
        return $this->requireConfig(['wallet_address', 'chain_type']);
    }

    public function createPayment(array $params): array
    {
        if (!$this->validateConfig()) {
            throw new \RuntimeException('USDT 配置不完整');
        }

        $chainType = $this->config['chain_type'] ?? 'trc20'; // trc20 或 erc20
        $contractAddress = $this->getContractAddress($chainType);

        return [
            'code'        => 'usdt',
            'trade_no'    => $params['trade_no'],
            'amount'      => $params['amount'],
            'method'      => 'crypto_transfer',
            'chain_type'  => $chainType,
            'wallet_address' => $this->config['wallet_address'],
            'contract_address' => $contractAddress,
            'amount_usdt' => $params['amount'],  // USDT amount
            'status'      => 'pending',
            'message'     => '请转账 ' . $params['amount'] . ' USDT 到指定钱包',
        ];
    }

    public function verifyCallback(array $callback): bool
    {
        // USDT 支付通常通过区块链浏览器或钱包 Webhook 确认
        // 回调应包含交易哈希（tx_hash）、确认数等信息
        
        $txHash = $callback['tx_hash'] ?? '';
        $fromAddress = $callback['from_address'] ?? '';
        $toAddress = $callback['to_address'] ?? '';
        $amount = $callback['amount'] ?? 0;
        $chainType = $callback['chain_type'] ?? '';
        
        // 验证接收地址是否匹配
        if ($toAddress !== $this->config['wallet_address']) {
            Log::error('USDT 支付地址不匹配: ' . $toAddress);
            return false;
        }

        // 验证金额是否匹配（留一定精度容差）
        if (abs((float)$amount - (float)$callback['expected_amount']) > 0.01) {
            Log::error('USDT 支付金额不匹配');
            return false;
        }

        // 验证交易哈希是否有效（可选，需要连接区块链 RPC）
        // $this->verifyTxHash($txHash, $chainType);

        return true;
    }

    public function handleCallback(array $callback): array
    {
        if (!$this->verifyCallback($callback)) {
            return [
                'success' => false,
                'message' => 'USDT 支付验证失败',
                'code' => 'VERIFICATION_ERROR',
            ];
        }

        $confirmations = (int)($callback['confirmations'] ?? 0);
        $requiredConfirmations = (int)($this->config['required_confirmations'] ?? 6);

        if ($confirmations < $requiredConfirmations) {
            return [
                'success' => false,
                'message' => "等待区块确认 ({$confirmations}/{$requiredConfirmations})",
                'status' => 'pending',
                'confirmations' => $confirmations,
            ];
        }

        return [
            'success' => true,
            'message' => 'USDT 支付成功',
            'trade_no' => $callback['trade_no'] ?? '',
            'status' => 'paid',
            'tx_hash' => $callback['tx_hash'] ?? '',
            'amount' => $callback['amount'] ?? 0,
            'chain_type' => $callback['chain_type'] ?? '',
            'confirmations' => $confirmations,
        ];
    }

    public function queryOrder(string $tradeNo): array
    {
        // 需要实现通过区块链浏览器 API 查询订单状态
        // 例如: Tron 的 Tronscan API 或 Ethereum 的 Etherscan API
        
        return [
            'status' => 'unknown',
            'message' => 'USDT 订单查询需要集成区块链浏览器 API',
            'trade_no' => $tradeNo,
        ];
    }

    /**
     * 获取 USDT 合约地址
     * 
     * @param string $chainType trc20 或 erc20
     * @return string 合约地址
     */
    private function getContractAddress(string $chainType): string
    {
        return match($chainType) {
            'trc20' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',  // USDT-TRC20 on Tron
            'erc20' => '0xdac17f958d2ee523a2206206994597c13d831ec7',  // USDT-ERC20 on Ethereum
            default => '',
        };
    }

    /**
     * 验证交易哈希（需要连接区块链节点）
     * 
     * @param string $txHash 交易哈希
     * @param string $chainType 链类型
     * @return bool
     */
    private function verifyTxHash(string $txHash, string $chainType): bool
    {
        // 实现: 连接到区块链 RPC 节点并验证交易
        // 示例 (Tron):
        // $rpcUrl = 'https://api.trongrid.io/jsonrpc';
        // $response = $this->httpPost($rpcUrl, [...]);
        
        // 示例 (Ethereum):
        // $rpcUrl = 'https://mainnet.infura.io/v3/YOUR_INFURA_KEY';
        // $response = $this->httpPost($rpcUrl, [...]);
        
        return true;
    }
}
