<?php

namespace AndrewSvirin\Ebics\Services;

use AndrewSvirin\Ebics\Contracts\SignatureInterface;
use AndrewSvirin\Ebics\Exceptions\EbicsException;
use AndrewSvirin\Ebics\Factories\Crypt\AESFactory;
use AndrewSvirin\Ebics\Factories\Crypt\RSAFactory;
use AndrewSvirin\Ebics\Factories\OrderDataFactory;
use AndrewSvirin\Ebics\Models\Crypt\RSA;
use AndrewSvirin\Ebics\Models\KeyRing;
use AndrewSvirin\Ebics\Models\OrderData;
use AndrewSvirin\Ebics\Models\OrderDataEncrypted;
use RuntimeException;

/**
 * EBICS crypt/decrypt encode/decode hash functions.
 *
 * @license http://www.opensource.org/licenses/mit-license.html  MIT License
 * @author Andrew Svirin
 *
 * @internal
 */
class CryptService
{

    /**
     * @var RSAFactory
     */
    private $rsaFactory;

    /**
     * @var AESFactory
     */
    private $aesFactory;

    /**
     * @var RandomService
     */
    private $randomService;

    /**
     * @var OrderDataFactory
     */
    private $orderDataFactory;

    /**
     * @var ZipReader
     */
    private $zipReader;

    public function __construct()
    {
        $this->rsaFactory = new RSAFactory();
        $this->aesFactory = new AESFactory();
        $this->randomService = new RandomService();
        $this->orderDataFactory = new OrderDataFactory();
        $this->zipReader = new ZipReader();
    }

    /**
     * Calculate hash.
     *
     * @param string $text
     * @param string $algorithm
     *
     * @return string
     */
    public function calculateHash(string $text, string $algorithm = 'sha256'): string
    {
        return hash($algorithm, $text, true);
    }

    /**
     * Decrypt encrypted OrderData.
     *
     * @param KeyRing $keyRing
     * @param OrderDataEncrypted $orderData
     *
     * @return OrderData
     * @throws EbicsException
     */
    public function decryptOrderData(KeyRing $keyRing, OrderDataEncrypted $orderData): OrderData
    {
        $orderDataContent = $this->decryptOrderDataContent($keyRing, $orderData);
        $orderData = $this->orderDataFactory->buildOrderDataFromContent($orderDataContent);

        return $orderData;
    }

    /**
     * Decrypt encrypted OrderData items.
     * Unzip order data items.
     *
     * @param KeyRing $keyRing
     * @param OrderDataEncrypted $orderData
     *
     * @return OrderData[]
     * @throws EbicsException
     */
    public function decryptOrderDataItems(KeyRing $keyRing, OrderDataEncrypted $orderData): array
    {
        $orderDataContent = $this->decryptOrderDataContent($keyRing, $orderData);

        $orderDataXmlItems = $this->zipReader->extractFilesFromString($orderDataContent);

        $orderDataItems = [];
        foreach ($orderDataXmlItems as $orderDataXmlItem) {
            $orderDataItems[] = $this->orderDataFactory->buildOrderDataFromContent($orderDataXmlItem);
        }

        return $orderDataItems;
    }

    /**
     * Decrypt encrypted OrderData.
     *
     * @param KeyRing $keyRing
     * @param OrderDataEncrypted $orderData
     *
     * @return string
     * @throws EbicsException
     */
    public function decryptOrderDataContent(
        KeyRing $keyRing,
        OrderDataEncrypted $orderData
    ): string {
        if (!($signatureE = $keyRing->getUserSignatureE())) {
            throw new RuntimeException('Signature E is not set.');
        }

        $rsa = $this->rsaFactory->create();
        $rsa->setPassword($keyRing->getPassword());
        $rsa->loadKey($signatureE->getPrivateKey());
        $transactionKeyDecrypted = $rsa->decrypt($orderData->getTransactionKey());
        // aes-128-cbc encrypting format.
        $aes = $this->aesFactory->create();
        $aes->setKeyLength(128);
        $aes->setKey($transactionKeyDecrypted);
        // Force openssl_options.
        $aes->setOpenSSLOptions(OPENSSL_ZERO_PADDING);
        $decrypted = $aes->decrypt($orderData->getOrderData());

        // Try to uncompress from gz order data.
        if (!($orderData = gzuncompress($decrypted))) {
            throw new RuntimeException('Order Data were uncompressed wrongly.');
        }
        return $orderData;
    }

    /**
     * Calculate signatureValue by encrypting Signature value with user Private key.
     *
     * @param KeyRing $keyRing
     * @param string $hash
     *
     * @return string Base64 encoded
     *
     * @throws EbicsException
     */
    public function cryptSignatureValue(
        KeyRing $keyRing,
        string $hash
    ): string {
        $digestToSignBin = $this->filter($hash);

        if (!($signatureX = $keyRing->getUserSignatureX()) || !($privateKey = $signatureX->getPrivateKey())) {
            throw new EbicsException(
                'On this stage must persist Signature X for authorization. ' .
                'Run INI and HIA requests for retrieve them.'
            );
        }

        $passphrase = $keyRing->getPassword();
        $rsa = $this->rsaFactory->create();
        $rsa->setPassword($passphrase);
        $rsa->loadKey($privateKey, RSA::PRIVATE_FORMAT_PKCS1);
        if (!defined('CRYPT_RSA_PKCS15_COMPAT')) {
            define('CRYPT_RSA_PKCS15_COMPAT', true);
        }
        $encrypted = $rsa->encrypt($digestToSignBin);
        if (empty($encrypted)) {
            throw new EbicsException('Incorrect authorization.');
        }

        return $encrypted;
    }

    /**
     * Generate public and private keys.
     *
     * @param KeyRing $keyRing
     * @param string $algorithm
     * @param int $length
     *
     * @return array = [
     *      'publickey' => '<string>',
     *      'privatekey' => '<string>',
     *  ]
     * @throws EbicsException
     */
    public function generateKeys(
        KeyRing $keyRing,
        string $algorithm = 'sha256',
        int $length = 2048
    ): array {
        $rsa = $this->rsaFactory->create();
        $rsa->setPublicKeyFormat(RSA::PRIVATE_FORMAT_PKCS1);
        $rsa->setPrivateKeyFormat(RSA::PUBLIC_FORMAT_PKCS1);
        $rsa->setHash($algorithm);
        $rsa->setPassword($keyRing->getPassword());

        return $rsa->createKey($length);
    }

    /**
     * Filter hash of blocked characters.
     *
     * @param string $hash
     *
     * @return string
     */
    private function filter(
        string $hash
    ): string {
        $RSA_SHA256prefix = [
            0x30,
            0x31,
            0x30,
            0x0D,
            0x06,
            0x09,
            0x60,
            0x86,
            0x48,
            0x01,
            0x65,
            0x03,
            0x04,
            0x02,
            0x01,
            0x05,
            0x00,
            0x04,
            0x20,
        ];
        $unpHash = $this->binToArray($hash);
        $signedInfoDigest = array_values($unpHash);
        $digestToSign = [];
        $this->systemArrayCopy($RSA_SHA256prefix, 0, $digestToSign, 0, count($RSA_SHA256prefix));
        $this->systemArrayCopy($signedInfoDigest, 0, $digestToSign, count($RSA_SHA256prefix), count($signedInfoDigest));

        return $this->arrayToBin($digestToSign);
    }

    /**
     * System.arrayCopy java function interpretation.
     *
     * @param array $a
     * @param int $c
     * @param array $b
     * @param int $d
     * @param int $length
     */
    private function systemArrayCopy(
        array $a,
        int $c,
        array &$b,
        int $d,
        int $length
    ): void {
        for ($i = 0; $i < $length; ++$i) {
            $b[$i + $d] = $a[$i + $c];
        }
    }

    /**
     * Pack array of bytes to one bytes-string.
     *
     * @param array<int, int> $bytes
     *
     * @return string (bytes)
     */
    private function arrayToBin(
        array $bytes
    ): string {
        return call_user_func_array('pack', array_merge(['c*'], $bytes));
    }

    /**
     * Convert bytes to array.
     *
     * @param string $bytes
     *
     * @return array
     */
    public function binToArray(
        string $bytes
    ): array {
        $result = unpack('C*', $bytes);
        if (false === $result) {
            throw new RuntimeException('Can not convert bytes to array.');
        }
        return $result;
    }

    /**
     * Calculate Public Digest.
     *
     * Try to use certificate public key prioritized.
     *
     * Concat the exponent and modulus (hex representation) with a single whitespace.
     * Remove leading zeros from both.
     * Calculate digest (SHA256).
     *
     * @param SignatureInterface $signature
     * @param string $algorithm
     * @param bool $rawOutput
     *
     * @return string
     */
    public function calculateDigest(
        SignatureInterface $signature,
        $algorithm = 'sha256',
        $rawOutput = false
    ): string {
        $rsa = $this->rsaFactory->create();
        $rsa->loadKey($signature->getPublicKey());


        $exponent = $rsa->getExponent()->toHex(true);
        $modulus = $rsa->getModulus()->toHex(true);
        // If key was formed with switched Modulus and Exponent, then change the place of key parts.
        // It can happens for Bank.
        if (strlen($exponent) > strlen($modulus)) {
            $buffer = $exponent;
            $exponent = $modulus;
            $modulus = $buffer;
        }
        $key = $this->calculateKey($exponent, $modulus);

        return $this->calculateKeyHash($key, $algorithm, $rawOutput);
    }

    /**
     * Make key from  exponent and modulus.
     *
     * @param string $exponent
     * @param string $modulus
     *
     * @return string
     */
    public function calculateKey(
        string $exponent,
        string $modulus
    ): string {
        // Remove leading 0.
        $exponent = ltrim($exponent, '0');
        $modulus = ltrim($modulus, '0');

        return sprintf('%s %s', $exponent, $modulus);
    }

    /**
     * Make key hash.
     *
     * @param string $key
     * @param string $algorithm
     * @param bool $rawOutput
     *
     * @return string
     */
    public function calculateKeyHash(
        string $key,
        string $algorithm = 'sha256',
        bool $rawOutput = false
    ): string {
        return hash($algorithm, $key, $rawOutput);
    }

    /**
     * generate 16 pseudo bytes.
     *
     * @return string
     */
    public function generateNonce(): string
    {
        $nonce = $this->randomService->hex(32);

        return $nonce;
    }

    /**
     * Transform public key on exponent and modulus.
     *
     * @param string $publicKey
     *
     * @return array = [
     *   'e' => '<bytes>',
     *   'm' => '<bytes>',
     * ]
     */
    public function getPublicKeyDetails(
        string $publicKey
    ): array {
        $rsa = $this->rsaFactory->create();
        $rsa->setPublicKey($publicKey);

        return [
            'e' => $rsa->getExponent()->toBytes(),
            'm' => $rsa->getModulus()->toBytes(),
        ];
    }
}
