<?php

require_once '../Api/KakaoTalkApi.php';

class Client
{
    private $email;
    private $password;
    private string $deviceName;
    private string $authToken;
    
    private string $dataFile = __DIR__.'/data/tokens.json';
    private string $logFile = __DIR__.'/data/status.log';
    private KakaoTalkAPI $api;

    public function __construct($email=null, $password=null, $deviceName="iPHPad")
    {
        $this->email = $email;
        $this->password = $password;
        $this->deviceName = $deviceName;
    }
    
    public function setCred($cred) {
        $this->authToken = $cred->auth;
    }

    private function log(string $msg): void
    {
        
        $entry = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;;
        file_put_contents($this->logFile, $entry, FILE_APPEND);
        
    }

    private function loadOrCreateUUID(array &$data): string
    {
        if (!isset($data['device_uuid'])) {
            $data['device_uuid'] = bin2hex(random_bytes(20));
            $this->log("새로운 UUID 생성: " . $data['device_uuid']);
        } else {
            $this->log("기존 UUID 로드: " . $data['device_uuid']);
        }
        return $data['device_uuid'];
    }

    private function loadTokens(): array
    {
        return file_exists($this->dataFile)
            ? json_decode(file_get_contents($this->dataFile), true)
            : [];
    }

    private function saveData(array $data): void
    {
        file_put_contents($this->dataFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function ensureAuthenticated(): void
    {
        $data = $this->loadTokens();
        $uuid = $this->loadOrCreateUUID($data);
        $this->log($uuid);
        $deviceInfo = ['uuid' => $uuid, 'name' => $this->deviceName];
        $this->api = new KakaoTalkAPI($this->email, $this->password, $deviceInfo);

        $isAuthenticated = false;

        if (isset($data['access_token'], $data['refresh_token'])) {
            $this->log("저장된 토큰 발견, 갱신 시도 중...");
            $this->api->setTokens($data['access_token'], $data['refresh_token']);
            try {
                $result = $this->api->refreshTokens();
                if (($result['status'] ?? -1) !== 0) {
                    throw new Exception("토큰 갱신 실패".print_r($result, true));
                }
                $tokens = $this->api->getTokens();
                $data['access_token'] = $tokens['access_token'];
                $data['refresh_token'] = $tokens['refresh_token'];
                $this->saveData($data);
                $this->log("토큰 갱신 성공");
                $isAuthenticated = true;
            } catch (Exception $e) {
                $this->log("토큰 갱신 실패: " . $e->getMessage());
                $isAuthenticated = false;
            }
        }

        if (!$isAuthenticated) {
            $this->log("전체 인증 절차 시작...");
            $passcodeResult = $this->api->generatePasscode();
            if (($passcodeResult['status'] ?? -1) !== 0) {
                throw new Exception("패스코드 생성 실패: " . json_encode($passcodeResult));
            }
            $this->log("패스코드: " . $passcodeResult['passcode']);
            if (!empty($this->authToken)) {
            $authPass = $this->api->authorizePasscode($passcodeResult['passcode'], $this->authToken);
            $this->log(json_encode($authPass));
            } else {
            $this->log("60초 안에 입력해주세요. 등록 대기 시작...");
            }
            $registrationSuccess = false;
        for ($i = 0; $i < 60; $i++) {
            $registerResult = $api->registerDevice();
            if (($res['status'] ?? -1) === 0) {
                $this->log("기기 등록 성공");
                $registered = true;
                break;
             } else {
                $this->log(json_encode($res));
             }
             sleep(1);
         }
                
         if (!$registrationSuccess) {
            throw new Exception("시간(60초) 내에 기기 등록이 완료되지 않았습니다.");
        }

            $loginResult = $this->api->login();
            if (($loginResult['status'] ?? -1) !== 0) {
                throw new Exception("로그인 실패: " . json_encode($loginResult));
            }

            $tokens = $this->api->getTokens();
            $data['access_token'] = $tokens['access_token'];
            $data['refresh_token'] = $tokens['refresh_token'];
            $this->saveData($data);
            $this->log("로그인 및 토큰 저장 성공");
        }
    }

    public function sendMessage($chatId, $message, $attachment = null)
    {
        try {
            $this->ensureAuthenticated();
            $result = $this->api->sendMessage($chatId, $message, $attachment);
            if (($result['result'] ?? '') === 'ok') {
                $this->log("메시지 전송 성공");
            } else {
                $this->log("메시지 전송 실패: " . json_encode($result));
            }
        } catch (Exception $e) {
            $this->log("오류 발생: " . $e->getMessage());
        }
        return json_encode($result);
    }
}