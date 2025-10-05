<?php

require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/rainbow_dns.php';

/**
 * 统一DNS API管理器
 * 支持多种DNS提供商
 */
class DNSManager {
    private $provider_type;
    private $api_instance;
    
    const PROVIDER_CLOUDFLARE = 'cloudflare';
    const PROVIDER_RAINBOW = 'rainbow';
    
    public function __construct($domain_config) {
        $this->provider_type = $domain_config['provider_type'] ?? self::PROVIDER_CLOUDFLARE;
        
        switch ($this->provider_type) {
            case self::PROVIDER_CLOUDFLARE:
                $this->api_instance = new CloudflareAPI(
                    $domain_config['api_key'],
                    $domain_config['email']
                );
                break;
                
            case self::PROVIDER_RAINBOW:
                $this->api_instance = new RainbowDNSAPI(
                    $domain_config['provider_uid'],
                    $domain_config['api_key'],
                    $domain_config['api_base_url']
                );
                break;
                
            default:
                throw new Exception('不支持的DNS提供商: ' . $this->provider_type);
        }
    }
    
    /**
     * 获取DNS记录列表
     */
    public function getDNSRecords($zone_id) {
        switch ($this->provider_type) {
            case self::PROVIDER_CLOUDFLARE:
                return $this->api_instance->getDNSRecords($zone_id);
                
            case self::PROVIDER_RAINBOW:
                $response = $this->api_instance->getDNSRecords($zone_id);
                // 转换为统一格式
                $records = [];
                if (isset($response['rows'])) {
                    foreach ($response['rows'] as $record) {
                        $records[] = $this->api_instance->formatRecord($record);
                    }
                }
                return $records;
                
            default:
                throw new Exception('不支持的DNS提供商');
        }
    }
    
    /**
     * 创建DNS记录
     */
    public function createDNSRecord($zone_id, $type, $name, $content, $options = []) {
        return $this->addDNSRecord($zone_id, $type, $name, $content, $options);
    }
    
    /**
     * 添加DNS记录
     */
    public function addDNSRecord($zone_id, $type, $name, $content, $options = []) {
        switch ($this->provider_type) {
            case self::PROVIDER_CLOUDFLARE:
                $proxied = $options['proxied'] ?? false;
                return $this->api_instance->addDNSRecord($zone_id, $type, $name, $content, $proxied);
                
            case self::PROVIDER_RAINBOW:
                $line = $options['line'] ?? 'default';
                $ttl = $options['ttl'] ?? 600;
                $rainbow_options = [];
                
                if (isset($options['mx'])) {
                    $rainbow_options['mx'] = $options['mx'];
                }
                if (isset($options['weight'])) {
                    $rainbow_options['weight'] = $options['weight'];
                }
                if (isset($options['remark'])) {
                    $rainbow_options['remark'] = $options['remark'];
                }
                
                $response = $this->api_instance->addDNSRecord($zone_id, $name, $type, $content, $line, $ttl, $rainbow_options);
                
                // 返回统一格式，包含RecordId
                return [
                    'id' => $response['RecordId'] ?? null,
                    'RecordId' => $response['RecordId'] ?? null, // 彩虹DNS使用RecordId
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => $ttl,
                    'proxied' => false
                ];
                
            default:
                throw new Exception('不支持的DNS提供商');
        }
    }
    
    /**
     * 更新DNS记录
     */
    public function updateDNSRecord($zone_id, $record_id, $type, $name, $content, $options = []) {
        switch ($this->provider_type) {
            case self::PROVIDER_CLOUDFLARE:
                $proxied = $options['proxied'] ?? false;
                return $this->api_instance->updateDNSRecord($zone_id, $record_id, $type, $name, $content, $proxied);
                
            case self::PROVIDER_RAINBOW:
                $line = $options['line'] ?? 'default';
                $ttl = $options['ttl'] ?? 600;
                $rainbow_options = [];
                
                if (isset($options['mx'])) {
                    $rainbow_options['mx'] = $options['mx'];
                }
                if (isset($options['weight'])) {
                    $rainbow_options['weight'] = $options['weight'];
                }
                if (isset($options['remark'])) {
                    $rainbow_options['remark'] = $options['remark'];
                }
                
                return $this->api_instance->updateDNSRecord($zone_id, $record_id, $name, $type, $content, $line, $ttl, $rainbow_options);
                
            default:
                throw new Exception('不支持的DNS提供商');
        }
    }
    
    /**
     * 删除DNS记录
     */
    public function deleteDNSRecord($zone_id, $record_id) {
        switch ($this->provider_type) {
            case self::PROVIDER_CLOUDFLARE:
                return $this->api_instance->deleteDNSRecord($zone_id, $record_id);
                
            case self::PROVIDER_RAINBOW:
                return $this->api_instance->deleteDNSRecord($zone_id, $record_id);
                
            default:
                throw new Exception('不支持的DNS提供商');
        }
    }
    
    /**
     * 验证API凭据
     */
    public function verifyCredentials() {
        return $this->api_instance->verifyCredentials();
    }
    
    /**
     * 获取验证详情
     */
    public function getVerificationDetails() {
        return $this->api_instance->getVerificationDetails();
    }
    
    /**
     * 获取域名列表（仅彩虹DNS支持）
     */
    public function getDomains($offset = 0, $limit = 100, $kw = '') {
        if ($this->provider_type === self::PROVIDER_RAINBOW) {
            return $this->api_instance->getDomains($offset, $limit, $kw);
        }
        
        throw new Exception('当前DNS提供商不支持获取域名列表');
    }
    
    /**
     * 获取提供商类型
     */
    public function getProviderType() {
        return $this->provider_type;
    }
    
    /**
     * 检查是否支持代理功能
     */
    public function supportsProxy() {
        return $this->provider_type === self::PROVIDER_CLOUDFLARE;
    }
    
    /**
     * 检查是否支持线路功能
     */
    public function supportsLine() {
        return $this->provider_type === self::PROVIDER_RAINBOW;
    }
    
    /**
     * 获取支持的DNS记录类型
     */
    public function getSupportedRecordTypes() {
        switch ($this->provider_type) {
            case self::PROVIDER_CLOUDFLARE:
                return ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'PTR', 'SRV', 'CAA'];
                
            case self::PROVIDER_RAINBOW:
                return ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'];
                
            default:
                return ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];
        }
    }
    
    /**
     * 获取提供商显示名称
     */
    public function getProviderDisplayName() {
        switch ($this->provider_type) {
            case self::PROVIDER_CLOUDFLARE:
                return 'Cloudflare';
                
            case self::PROVIDER_RAINBOW:
                return '彩虹聚合DNS';
                
            default:
                return '未知提供商';
        }
    }
    
    /**
     * 获取所有支持的提供商
     */
    public static function getSupportedProviders() {
        return [
            self::PROVIDER_CLOUDFLARE => 'Cloudflare',
            self::PROVIDER_RAINBOW => '彩虹聚合DNS'
        ];
    }
}