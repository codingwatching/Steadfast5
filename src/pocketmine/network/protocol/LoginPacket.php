<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____  
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \ 
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/ 
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_| 
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 * 
 *
 */

namespace pocketmine\network\protocol;

#include <rules/DataPacket.h>

use pocketmine\Server;
use pocketmine\utils\Binary;
use pocketmine\utils\JWT;
use pocketmine\utils\UUID;

class LoginPacket extends PEPacket {

	const NETWORK_ID = Info::LOGIN_PACKET;
	const PACKET_NAME = "LOGIN_PACKET";
	const MOJANG_ROOT_KEY = "MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAE8ELkixyLcwlZryUQcu1TvPOmI2B7vX83ndnWRUaXm74wFfa5f/lwQNTfrLVHa2PmenpGI6JhIMUJaWZrjmMj90NoKNFSNBuKdm8rYiXsfaz3K36x/1U26HpG0ZxK/V1V";

	public $username;
	public $protocol1;
	public $protocol2;
	public $clientId;
	public $clientUUID;
	public $serverAddress;
	public $clientSecret;
	public $slim = false;
	public $skin = "";
	public $skinName;
	public $chainsDataLength;
	public $chains;
	public $playerDataLength;
	public $playerData;
	public $isValidProtocol = true;
	public $inventoryType = -1;
	public $osType = -1;
	public $xuid = '';
	public $languageCode = 'unknown';
	public $clientVersion = 'unknown';
	public $originalProtocol;
	public $skinGeometryName = "";
	public $skinGeometryData = "";
	public $capeData = "";
	public $isVerified = true;
	public $premiunSkin = "";
	public $identityPublicKey = "";
	public $platformChatId = "";
	public $additionalSkinData = [];

	private $valid = true;
	private $authenticated = false;

	private function getFromString(&$body, $len) {
		$res = substr($body, 0, $len);
		$body = substr($body, $len);
		return $res;
	}

	public function decode($playerProtocol) {
		$acceptedProtocols = Info::ACCEPTED_PROTOCOLS;
		// header: protocolID, Subclient Sender, Subclient Receiver
		$this->getVarInt(); // header: 1 byte for protocol < 280, 1-2 for 280
		$tmpData = Binary::readInt(substr($this->getBuffer(), $this->getOffset(), 4));
		if ($tmpData == 0) {
			$this->getShort();
		}
		$this->protocol1 = $this->getInt();
		if (!in_array($this->protocol1, $acceptedProtocols)) {
			$this->isValidProtocol = false;
			return;
		}
		$data = $this->getString();
		if (ord($data{0}) != 120 || (($decodedData = @zlib_decode($data)) === false)) {
			$body = $data;
		} else {
			$body = $decodedData;
		}
		$this->chainsDataLength = Binary::readLInt($this->getFromString($body, 4));
		$this->chains = json_decode($this->getFromString($body, $this->chainsDataLength), true);

		$this->playerDataLength = Binary::readLInt($this->getFromString($body, 4));
		$this->playerData = $this->getFromString($body, $this->playerDataLength);

		$isNeedVerify = Server::getInstance()->isUseEncrypt();
		$dataIndex = $this->findDataIndex($isNeedVerify);
		if (is_null($dataIndex)) {
			$this->isValidProtocol = false;
			return;
		}
		$this->getPlayerData($dataIndex, $isNeedVerify);
	}

	public function encode($playerProtocol) {
		
	}

	private function findDataindex($isNeedVerify) {
		$dataIndex = null;
		$validationKey = null;
		$this->chains['data'] = array();
		$index = 0;
		if ($isNeedVerify) {
			foreach ($this->chains['chain'] as $key => $jwt) {
				$data = JWT::parseJwt($jwt);
				if ($data) {
					if (self::MOJANG_ROOT_KEY == $data['header']['x5u']) {
						$validationKey = $data['payload']['identityPublicKey'];
					} elseif ($validationKey != null && $validationKey == $data['header']['x5u']) {
						$dataIndex = $index;
					} else {
						if (!isset($data['payload']['extraData'])) continue;
						$data['payload']['extraData']['XUID'] = "";
						$this->isVerified = false;
						$dataIndex = $index;
					}
					$this->chains['data'][$index] = $data['payload'];
					$index++;
				} else {
					$this->isVerified = false;
				}
			}
		} else {
			foreach ($this->chains['chain'] as $key => $jwt) {
				$data = self::load($jwt);
				if (isset($data['extraData'])) {
					$dataIndex = $index;
				}
				$this->chains['data'][$index] = $data;
				$index++;
			}
		}
		return $dataIndex;
	}

	private function getPlayerData($dataIndex, $isNeedVerify) {
		if ($isNeedVerify) {
			$this->playerData = JWT::parseJwt($this->playerData);
			if ($this->playerData) {
				if (!$this->playerData['isVerified']) {
					$this->isVerified = false;
				}
				$this->playerData = $this->playerData['payload'];
			} else {
				$this->isVerified = false;
				return;
			}
		} else {
			$this->playerData = self::load($this->playerData);
		}
		$this->username = $this->chains['data'][$dataIndex]['extraData']['displayName'];
		$this->clientId = $this->chains['data'][$dataIndex]['extraData']['identity'];
		$this->clientUUID = UUID::fromString($this->chains['data'][$dataIndex]['extraData']['identity']);
		$this->identityPublicKey = $this->chains['data'][$dataIndex]['identityPublicKey'];
		if (isset($this->chains['data'][$dataIndex]['extraData']['XUID'])) {
			$this->xuid = $this->chains['data'][$dataIndex]['extraData']['XUID'];
		}
		
		$this->serverAddress = $this->playerData['ServerAddress'];
		$this->skinName = $this->playerData['SkinId'];
		$this->skin = base64_decode($this->playerData['SkinData']);		
		if (isset($this->playerData['SkinGeometryName'])) {
			$this->skinGeometryName = $this->playerData['SkinGeometryName'];
		}
		if (isset($this->playerData['SkinGeometry'])) {
			$this->skinGeometryData = base64_decode($this->playerData['SkinGeometry']);
		} elseif (isset($this->playerData['SkinGeometryData'])) {
			$this->skinGeometryData = base64_decode($this->playerData['SkinGeometryData']);
			if (strpos($this->skinGeometryData, 'null') === 0) {
				$this->skinGeometryData = '';
			}
		}
		$this->clientSecret = $this->playerData['ClientRandomId'];
		if (isset($this->playerData['DeviceOS'])) {
			$this->osType = $this->playerData['DeviceOS'];
		}
		if (isset($this->playerData['UIProfile'])) {
			$this->inventoryType = $this->playerData['UIProfile'];
		}
		if (isset($this->playerData['LanguageCode'])) {
			$this->languageCode = $this->playerData['LanguageCode'];
		}
		if (isset($this->playerData['GameVersion'])) {
			$this->clientVersion = $this->playerData['GameVersion'];
		}
		if (isset($this->playerData['CapeData'])) {
			$this->capeData = base64_decode($this->playerData['CapeData']);
		}
		if (isset($this->playerData["PremiumSkin"])) {
			$this->premiunSkin = $this->playerData["PremiumSkin"];
		}
		if (isset($this->playerData["PlatformOnlineId"])) {
			$this->platformChatId = $this->playerData["PlatformOnlineId"];
		}
		$this->originalProtocol = $this->protocol1;
		$this->protocol1 = self::convertProtocol($this->protocol1);		
		$additionalSkinDataList = [
			'AnimatedImageData', 'CapeId', 'CapeImageHeight', 'CapeImageWidth', 'CapeOnClassicSkin', 'PersonaSkin', 'PremiumSkin', 'SkinAnimationData', 'SkinImageHeight', 'SkinImageWidth', 'SkinResourcePatch'	
		];
		$additionalSkinData = [];
		foreach ($additionalSkinDataList as $propertyName) {
			if (isset($this->playerData[$propertyName])) {
				$additionalSkinData[$propertyName] = $this->playerData[$propertyName];
			}
		}
		if (isset($additionalSkinData['AnimatedImageData'])) {
			foreach ($additionalSkinData['AnimatedImageData'] as &$animation) {
				$animation['Image'] = base64_decode($animation['Image']);
			}
		}
		if (isset($additionalSkinData['SkinResourcePatch'])) {
			$additionalSkinData['SkinResourcePatch'] = base64_decode($additionalSkinData['SkinResourcePatch']);
		}
		if (isset($this->playerData["PersonaPieces"])) {
			$additionalSkinData['PersonaPieces'] = $this->playerData["PersonaPieces"];
		}
		if (isset($this->playerData["ArmSize"])) {
			$additionalSkinData['ArmSize'] = $this->playerData["ArmSize"];
		}
		if (isset($this->playerData["SkinColor"])) {
			$additionalSkinData['SkinColor'] = $this->playerData["SkinColor"];
		}
		if (isset($this->playerData["PieceTintColors"])) {
			$additionalSkinData['PieceTintColors'] = $this->playerData["PieceTintColors"];
		}
		$this->additionalSkinData = $additionalSkinData;
		$this->checkSkinData($this->skin, $this->skinGeometryName, $this->skinGeometryData, $this->additionalSkinData);
	}

	public function onRun() {
		$packet = $this->packet;
		$currentKey = null;
		foreach ($packet->chainData["chain"] as $jwt) {
			if (!$this->validateToken($jwt, $currentKey)) {
				$this->valid = false;
				return;
			}
		}
		if (!$this->validateToken($packet->clientDataJwt, $currentKey)) {
			$this->valid = false;
		}
	}

	private function validateToken(string $jwt, ?string &$currentPublicKey) {
		[$headB64, payloadB64, sigB64] = explode('.', $jwt);
		$headers = json_decode(base64_decode(strtr($headB64, '-_', '+/'), true), true);
		if ($currentPublicKey === null) {
			$currentPublicKey = $headers["x5u"];
		}
		$plainSignature = base64_decode(strtr($sigB64, '-_', '+/'), true);
		assert(strlen($plainSignature) === 96);
		[$rString, $sString] = str_split($plainSignature, 48);
		$rString = ltrim($rString, "\x00");
		if (ord($rString{0}) >= 128) {
			$rString = "\x00" . $rString;
		}
		$sString = ltrim($sString, "\x00");
		if (ord($sString{0}) >= 128) {
			$sString = "\x00" . $sString;
		}
		$sequence = "\x02" . chr(strlen($rString)) . $rString . "\x02" . chr(strlen($sString)) . $sString;
		$derSignature = "\x30" . chr(strlen($sequence)) . $sequence;
		$v = openssl_verify("$headB64.$payloadB64", $derSignature, "-----BEGIN PUBLIC KEY-----\n" . wordwrap($currentPublicKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----\n", OPENSSL_ALGO_SHA384);
		if ($v !== 1) {
			return false;
		}
		if ($currentPublicKey === self::MOJANG_ROOT_KEY) {
			$this->authenticated = true;
		}
		$claims = json_decode(base64_decode(strtr($payloadB64, '-_', '+/'), true), true);
		$time = time();
		if (isset($claims["nbf"]) && $claims["nbf"] > $time) {
			return false;
		}
		if (isset($claims["exp"]) && $claims["exp"] < $time) {
			return false;
		}
		$currentPublicKey = $claims["identityPublicKey"];
		return true;
	}

	public function onCompletion(Server $server) {
		$player = $this->fetchLocal($server);
		if ($player->isClosed()) {
			$server->getLogger()->error("Player " . $player->getName() . " was disconnected before their login could be verified");
		} else {
			$player->onVerifyComplete($this->packet, $this->valid, $this->authenticated);
		}
	}

	public static function load($jwsTokenString) {
		$parts = explode('.', $jwsTokenString);
		if (isset($parts[1])) {
			$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
			return $payload;
		}
		return "";
	}

}