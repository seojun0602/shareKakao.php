<?php

require_once __DIR__ . '/Config.php';

class KakaoTalkAPI
{
    private static $API_BASE_URL;
    private static $API_BASE_URL_SHARE ;

    private string $email;
    private string $password;
    private string $deviceName;
    private string $deviceUuid;
    private string $userAgent;
    private string $osVersion;
    private string $appVersion;
    private string $model;
    private string $language;

    private ?string $accessToken = null;
    private ?string $refreshToken = null;

public function __construct(?string $email = null, ?string $password = null, array $deviceInfo = [])
    {
        self::$API_BASE_URL = Config::$base_url;
        self::$API_BASE_URL_SHARE = Config::$SHARE_API_BASE;
        
        $this->email = $email ?? Config::$email;
        $this->password = $password ?? Config::$password;

        $this->deviceName = Config::$deviceName;
        $this->deviceUuid = ($deviceInfo["uuid"] ?? ((Config::$deviceId) ?? $this->generateDuuid()));
        $this->appVersion = Config::$version;
        $this->language = Config::$language;
        $this->userAgent = "KT/{$this->appVersion} An/9 {$this->language}";
        $this->osVersion = $deviceInfo['osVersion'] ?? Config::$osVersion;
        $this->model = Config::$Model;
    }

    public function generatePasscode()
    {
        $url = self::$API_BASE_URL . '/passcodeLogin/generate';
        $headers = [
            'X-VC: ' . $this->generateXvc($this->email),
            'Authorization: ' . $this->email,
        ];
        $data = [
            "email" => $this->email,
            "password" => $this->password,
            "permanent" => true,
            "device" => [
                "name" => $this->deviceName,
                "uuid" => $this->deviceUuid,
                "model" => $this->model,
                "osVersion" => $this->osVersion,
                "isOneStore" => false
            ]
        ];

        return $this->makeRequest($url, 'POST', $data, $headers, true);
    }
    
    public function authorizePasscode($passcode, $authToken): array
{
    $url = 'https://talk-pilsner.kakao.com/talk/account/' . '/passcodeLogin/authorize';
    $headers = [
        'Authorization: ' . $authToken,
        'Talk-Agent: android/10.4.3/' . $this->language,
        'Talk-Language: en',
        'Content-Type: application/json',
        'User-Agent: '.$this->userAgent,
    ];
    $data = [
        "passcode" => $passcode
    ];

    return $this->makeRequest($url, 'POST', $data, $headers, true);
    }


    public function registerDevice()
    {
        $url = self::$API_BASE_URL . '/passcodeLogin/registerDevice';
        $headers = [
            'X-VC: ' . $this->generateXvc($this->email),
            'Authorization: ' . $this->email,
        ];
        $data = [
            "email" => $this->email,
            "password" => $this->password,
            "device" => ["uuid" => $this->deviceUuid]
        ];
        

        return $this->makeRequest($url, 'POST', $data, $headers, true);
    }
    
    public function login(): array
    {
        $queryParams = http_build_query([
            'forced' => 'true',
            'permanent' => 'true',
            'one_store' => 'false',
            'device_name' => $this->deviceName,
            'email' => $this->email,
            'password' => $this->password,
            'device_uuid' => $this->deviceUuid
        ]);
        $url = self::$API_BASE_URL . '/login.json?' . $queryParams;
        $headers = [
            'X-VC: '.$this->generateXvc($this->email),
            'Authorization: ' . $this->email,
            'Accept-Language: ' . $this->language,
            'Accept-Encoding: gzip',
        ];

        $response = $this->makeRequest($url, 'POST', [], $headers, false, true);

        if (isset($response['status']) && $response['status'] === 0) {
            $this->accessToken = $response['access_token'];
            $this->refreshToken = $response['refresh_token'];
        }

        return $response;
    }

    public function refreshTokens()
    {
        if (!$this->refreshToken || !$this->accessToken) {
            throw new Exception("Refresh token or Access token is not available. Please login first.");
        }
        $url = self::$API_BASE_URL . '/oauth2_token.json';
        $headers = [
            'X-VC: ' . $this->generateXvc($this->email),
            'Authorization: ' . $this->accessToken . '-' . $this->deviceUuid,
        ];
        
        $data = [
            "grant_type" => "refresh_token",
            "access_token" => $this->accessToken,
            "refresh_token" => $this->refreshToken
        ];

        $response = $this->makeRequest($url, 'POST', $data, $headers, true);

        if (isset($response['status']) && $response['status'] === 0) {
            $this->accessToken = $response['access_token'];
            $this->refreshToken = $response['refresh_token'];
        }
        return $response;
    }

    public function sendMessage(int $chatId, string $message, $attachment = null)
    {
      if(gettype($attachment)=="object"){
          $type = $attachment?->getType() ?? 1;
          $attachmentData = $attachment ? json_encode($attachment->build(), JSON_UNESCAPED_UNICODE) : '{}';
      } else if ($attachment!=null){
        $type = 1;
        $attachmentData = $attachment ? json_encode($attachment, JSON_UNESCAPED_UNICODE) : '{}';
      } else {
         $type = 1;
         $attachmentData = null;
      }
        if (!$this->accessToken) {
            throw new Exception("Access token is not available. Please login first.");
        }
        
        $url = self::$API_BASE_URL_SHARE . '/authWrite';
        $headers = ['User-Agent: ' . Config::$userAgent];
        
        $postData = [
            'target' => json_encode(["chatId" => $chatId]),
            'chatLog' => json_encode(["type" => $type, "message" => $message, "extra" => $attachmentData]),
            'duuid' => $this->deviceUuid,
            'oauthToken' => $this->accessToken
        ];

        return $this->makeRequest($url, 'POST', $postData, $headers, false);
    }
    

    private function makeRequest(string $url, string $method, array $data, array $customHeaders, bool $isJson, bool $isGzip = false)
    {
        $ch = curl_init();

        $headers = array_merge([
            'User-Agent: ' . $this->userAgent,
            'A: ' . Config::$platform . '/' . $this->appVersion . '/' . $this->language,
            'Content-Type: ' . ($isJson ? 'application/json' : 'application/x-www-form-urlencoded'),
        ], $customHeaders);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $isJson ? json_encode($data) : http_build_query($data));
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($isGzip) {
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        }
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        return json_decode($response, true) ?? $response;
    }

    private function generateXvc(string $email, string $seed1 = 'CAREY', string $seed2 = 'GLENN', string $seed3 = 'PETER'): string
    {
        $data = sprintf("%s|%s|%s|%s|%s", $seed1, $this->userAgent, $seed2, $email, $seed3);
        return substr(hash('sha512', $data), 0, 16);
    }
    
    private function generateDuuid(): string
    {
        if (!empty(Config::$deviceId)) {
            return Config::$deviceId;
        }
        return bin2hex(random_bytes(20));
    }
    
    public function setTokens(string $accessToken, string $refreshToken): void
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
    }

    public function getTokens(): ?array
    {
        if ($this->accessToken && $this->refreshToken) {
            return [
                'access_token' => $this->accessToken,
                'refresh_token' => $this->refreshToken,
            ];
        }
        return null;
    }
}