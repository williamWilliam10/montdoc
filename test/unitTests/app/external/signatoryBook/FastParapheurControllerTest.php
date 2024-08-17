<?php

/**
* Copyright Maarch since 2008 under licence GPLv3.
* See LICENCE.txt file at the root folder for more details.
* This file is part of Maarch software.
*
*/

namespace MaarchCourrier\Tests\app\folder;

use ExternalSignatoryBook\controllers\FastParapheurController;
use MaarchCourrier\Tests\CourrierTestCase;

class FastParapheurTest extends CourrierTestCase
{
    private static $remoteSignatoryBookPath = null;
    private static $defaultRemoteSignatoryBookPath = "modules/visa/xml/remoteSignatoryBooks.xml.default";
    private static $generalFileConfigOriginalXml = null;

    protected function setUp(): void
    {
        self::$remoteSignatoryBookPath = "modules/visa/xml/remoteSignatoryBooks.xml";
        self::$generalFileConfigOriginalXml = file_get_contents(self::$defaultRemoteSignatoryBookPath);
        file_put_contents(self::$remoteSignatoryBookPath, self::$generalFileConfigOriginalXml);
    }

    public function testCheckSignatoryBookFileIsMissing()
    {
        // Arrange
        unlink(self::$remoteSignatoryBookPath);

        // Act
        $fastConfig = FastParapheurController::getConfig();

        // Assert
        $this->assertNotEmpty($fastConfig);
        $this->assertNotEmpty($fastConfig['code'], "Le code erreur n'existe pas");
        $this->assertNotEmpty($fastConfig['errors'], "Le message erreur n'existe pas");

        $this->assertSame($fastConfig['code'], 400);
        $this->assertSame($fastConfig['errors'], "SignatoryBooks configuration file missing or empty");
    }

    public function provideConfigFileWithoutFastParapheurConfig()
    {
        $xml = new \DOMDocument("1.0", "utf-8");
        $xmlRoot = $xml->createElement("root");
        $xmlSignatoryBookEnabled = $xml->createElement('signatoryBookEnabled', 'fastParapheur');
        $xmlSignatoryBook = $xml->createElement('signatoryBook');

        $xmlRoot->appendChild($xmlSignatoryBookEnabled);
        $xmlRoot->appendChild($xmlSignatoryBook);
        $xml->appendChild($xmlRoot);

        return [
            'Remote Signatory Books file data without FastParapheur' => [
                'input' => $xml->saveXML(),
                'expectedOutput' => [
                    'code' => 500,
                    'errors' => 'FastParapheur configuration is missing'
                ]
            ]
        ];
    }

    /**
     * @dataProvider provideConfigFileWithoutFastParapheurConfig
     */
    public function testCheckFastParapheurConfigIsMissing($input, $expectedOutput)
    {
        // Arrange
        unlink(self::$remoteSignatoryBookPath);
        file_put_contents(self::$remoteSignatoryBookPath, $input);

        // Act
        $fastConfig = FastParapheurController::getConfig();

        // Assert
        $this->assertNotEmpty($fastConfig);
        $this->assertIsArray($fastConfig, "La configuration n'est pas un tableau");
        $this->assertNotEmpty($fastConfig['code'], "Le code erreur n'existe pas");
        $this->assertNotEmpty($fastConfig['errors'], "Le message erreur n'existe pas");

        $this->assertSame($fastConfig['code'], $expectedOutput['code']);
        $this->assertSame($fastConfig['errors'], $expectedOutput['errors']);
    }

    public function provideAnMissingKeysFromConfig()
    {
        $doc = new \DOMDocument();
        $xmlStr = simplexml_load_file(self::$defaultRemoteSignatoryBookPath)->asXML();
        $doc->loadXML($xmlStr);

        $signatoryBookNode = $doc->getElementsByTagName('root')->item(0)->getElementsByTagName('signatoryBook')->item(3);

        // remove workflowTypes node
        $workflowTypesNode = $signatoryBookNode->getElementsByTagName('workflowTypes')->item(0);
        $signatoryBookNode->removeChild($workflowTypesNode);
        $xmlStrWithoutWorkflowTypes = $doc->saveXML();

        // put back workflowTypes and remove subscriberId
        $subscriberIdNode = $signatoryBookNode->getElementsByTagName('subscriberId')->item(0);
        $signatoryBookNode->appendChild($workflowTypesNode);
        $signatoryBookNode->removeChild($subscriberIdNode);
        $xmlStrWithoutSubscriberId = $doc->saveXML();

        // put back subscriberId and remove url
        $urlNode = $signatoryBookNode->getElementsByTagName('url')->item(0);
        $signatoryBookNode->appendChild($subscriberIdNode);
        $signatoryBookNode->removeChild($urlNode);
        $xmlStrWithoutUrl = $doc->saveXML();

        // put back url and remove certPath
        $certPathNode = $signatoryBookNode->getElementsByTagName('certPath')->item(0);
        $signatoryBookNode->appendChild($urlNode);
        $signatoryBookNode->removeChild($certPathNode);
        $xmlStrWithoutCertPath = $doc->saveXML();

        // put back certPath and remove certPass
        $certPassNode = $signatoryBookNode->getElementsByTagName('certPass')->item(0);
        $signatoryBookNode->appendChild($certPathNode);
        $signatoryBookNode->removeChild($certPassNode);
        $xmlStrWithoutCertPass = $doc->saveXML();

        // put back certPass and remove certType
        $certTypeNode = $signatoryBookNode->getElementsByTagName('certType')->item(0);
        $signatoryBookNode->appendChild($certPassNode);
        $signatoryBookNode->removeChild($certTypeNode);
        $xmlStrWithoutCertType = $doc->saveXML();

        // put back certType and remove validatedState
        $validatedStateNode = $signatoryBookNode->getElementsByTagName('validatedState')->item(0);
        $signatoryBookNode->appendChild($certTypeNode);
        $signatoryBookNode->removeChild($validatedStateNode);
        $xmlStrWithoutValidatedState = $doc->saveXML();

        // put back validatedState and remove refusedState
        $refusedStateNode = $signatoryBookNode->getElementsByTagName('refusedState')->item(0);
        $signatoryBookNode->appendChild($validatedStateNode);
        $signatoryBookNode->removeChild($refusedStateNode);
        $xmlStrWithoutRefusedState = $doc->saveXML();

        // put back refusedState and remove optionOtp
        $optionOtpNode = $signatoryBookNode->getElementsByTagName('optionOtp')->item(0);
        $signatoryBookNode->appendChild($refusedStateNode);
        $signatoryBookNode->removeChild($optionOtpNode);
        $xmlStrWithoutOptionOtp = $doc->saveXML();

        return [
            'Without workflowTypes' => [
                'input' => $xmlStrWithoutWorkflowTypes,
                'expectedOutput' => [
                    'code' => 500,
                    'errors' => 'workflowTypes not found for FastParapheur'
                ]
            ],
            'Without subscriberId' => [
                'input' => $xmlStrWithoutSubscriberId,
                'expectedOutput' => [
                    'code' => 500,
                    'errors' => 'subscriberId not found for FastParapheur'
                ]
            ],
            'Without url' => [
                'input' => $xmlStrWithoutUrl,
                'expectedOutput' => [
                    'code' => 500,
                    'errors' => 'url not found for FastParapheur'
                ]
            ],
            'Without certPath' => [
                'input' => $xmlStrWithoutCertPath,
                'expectedOutput' => [
                    'code' => 500,
                    'errors' => 'certPath not found for FastParapheur'
                ]
            ],
            'Without certPass' => [
                'input' => $xmlStrWithoutCertPass,
                'expectedOutput' => [
                    'code' => 500,
                    'errors' => 'certPass not found for FastParapheur'
                ]
            ],
            'Without certType' => [
                'input' => $xmlStrWithoutCertType,
                'expectedOutput' => [
                    'code' => 500,
                    'errors' => 'certType not found for FastParapheur'
                ]
            ],
            'Without validatedState' => [
                'input' => $xmlStrWithoutValidatedState,
                'expectedOutput' => [
                    'code' => 500,
                    'errors' => 'validatedState not found for FastParapheur'
                ]
            ],
            'Without refusedState' => [
                'input' => $xmlStrWithoutRefusedState,
                'expectedOutput' => [
                    'code' => 500,
                    'errors' => 'refusedState not found for FastParapheur'
                ]
            ]
        ];
    }

    /**
     * @dataProvider provideAnMissingKeysFromConfig
     */
    public function testCheckFastParapheurMissingConfigKeys($input, $expectedOutput)
    {
        // Arrange
        unlink(self::$remoteSignatoryBookPath);
        file_put_contents(self::$remoteSignatoryBookPath, $input);

        // Act
        $fastConfig = FastParapheurController::getConfig();

        // Assert
        $this->assertNotEmpty($fastConfig);
        $this->assertIsArray($fastConfig);

        // Assert
        $this->assertNotEmpty($fastConfig);
        $this->assertIsArray($fastConfig, "La configuration n'est pas un tableau");
        $this->assertNotEmpty($fastConfig['code'], "Le code erreur n'existe pas");
        $this->assertNotEmpty($fastConfig['errors'], "Le message erreur n'existe pas");

        $this->assertSame($fastConfig['code'], $expectedOutput['code']);
        $this->assertSame($fastConfig['errors'], $expectedOutput['errors']);
    }

    public function testGetOptionOtp()
    {
        $fastConfig = FastParapheurController::getConfig();
        $this->assertNotEmpty($fastConfig);
        $this->assertIsArray($fastConfig, "La configuration n'est pas un tableau");
        $this->assertArrayHasKey('optionOtp', $fastConfig, "La configuration ne contient pas 'optionOtp' comme clé");
        $this->assertSame($fastConfig['optionOtp'], 'false', "La configuration OTP n'est pas désactivé par défault");
    }

    protected function tearDown(): void
    {
        if (!empty(self::$remoteSignatoryBookPath) && file_exists(self::$remoteSignatoryBookPath)) {
            unlink(self::$remoteSignatoryBookPath);
        }
    }
}