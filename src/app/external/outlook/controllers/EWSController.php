<?php

namespace Outlook\controllers;

use History\models\BatchHistoryModel;
use jamesiarmes\PhpEws\Client;
use jamesiarmes\PhpEws\Request\GetAttachmentType;
use jamesiarmes\PhpEws\Request\GetItemType;
use jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfBaseItemIdsType;
use jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfRequestAttachmentIdsType;
use jamesiarmes\PhpEws\Enumeration\DefaultShapeNamesType;
use jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use jamesiarmes\PhpEws\Type\ItemResponseShapeType;
use jamesiarmes\PhpEws\Type\ItemIdType;
use jamesiarmes\PhpEws\Type\RequestAttachmentIdType;
use jamesiarmes\PhpEws\Type\ConnectingSIDType;
use jamesiarmes\PhpEws\Type\ExchangeImpersonationType;

use Convert\controllers\ConvertPdfController;
use Resource\controllers\StoreController;
use SrcCore\models\ValidatorModel;
use Respect\Validation\Validator;
use SrcCore\models\CurlModel;
use SrcCore\controllers\LogsController;


class EWSController {

    private const BASE_TOKEN_URL = 'https://login.microsoftonline.com/';

    public static function initOauth2(array $args)
    {
        $control = EWSController::control($args);
        if (!empty($control['errors'])) {
            return ['errors' => $control['errors'], 'lang' => $control['lang']];
        }

        $curlResponse = CurlModel::exec([
            'url'     => EWSController::BASE_TOKEN_URL . $args['tenantId'] . '/oauth2/v2.0/token',
            'method'  => 'POST',
            'multipartBody' => [
                'grant_type'    => 'client_credentials',
				'client_id'     => $args['clientId'],
				'client_secret' => $args['clientSecret'],
				'scope'         => 'https://' . $args['ewsHost'] . '/.default'
            ]
        ]);

        $errors = [];
        if (!empty($curlResponse['errors'])) {
            $errors[] = $curlResponse['errors'];
        }
        if (!empty($curlResponse['response']['error'])) {
            $errors[] = $curlResponse['response'];
        }
        if (!empty($errors)) {
            LogsController::add([
                'isTech'    => true,
                'moduleId'  => 'EWSController',
                'level'     => 'ERROR',
                'recordId'  => 'EWS OAuth2 Error',
                'eventType' => 'Curl',
                'eventId'   => 'Error while fetching access token, response: ' . json_encode($errors)
            ]);
            return ['errors' => $errors, 'lang' => 'outlookErrorGetToken'];
        }

        $accessToken = $curlResponse['response']['access_token'] ?? null;

        if (empty($accessToken)) {
            LogsController::add([
                'isTech'    => true,
                'moduleId'  => 'EWSController',
                'level'     => 'ERROR',
                'recordId'  => 'EWS OAuth2 Error',
                'eventType' => 'Get Access Token',
                'eventId'   => 'Error while fetching access token, response: ' . json_encode($curlResponse['response'])
            ]);
            return ['errors' => 'Error while fetching access token', 'lang' => 'outlookErrorGetToken'];
        }

        $client = null;

        try {
            $client = new Client($args['ewsHost'], $args['email'], '-', $args['version']);
            $client->setCurlOptions([
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken]
            ]);
            $exim = new ExchangeImpersonationType();
            $csid = new ConnectingSIDType();
            $csid->PrimarySmtpAddress = $args['email'];
            $exim->ConnectingSID = $csid;
            $client->setImpersonation($exim);
        } catch (\Exception $e) {
            LogsController::add([
                'isTech'    => true,
                'moduleId'  => 'EWSController',
                'level'     => 'ERROR',
                'recordId'  => 'Init EWS Client Error',
                'eventType' => 'EWS Client',
                'eventId'   => 'Error while initialize EWS Client. Exception : ' . $e->getMessage()
            ]);
            return ['errors' => 'Error while initialize EWS Client : ' . $e->getMessage()];
        }

        return ['client' => $client];
    }

    public static function control(array $args)
    {
        if (!Validator::notEmpty()->stringType()->validate($args['ewsHost'])) {
            return ['errors' => '[EWS] ewsHost is empty or not a string'];
        } elseif (!Validator::notEmpty()->stringType()->validate($args['email'])) {
            return ['errors' => '[EWS] email is empty or not a string'];
        } elseif (!Validator::notEmpty()->stringType()->validate($args['version'])) {
            return ['errors' => '[EWS] version is empty or not a string'];
        } elseif (!Validator::notEmpty()->stringType()->validate($args['tenantId'])) {
            return ['errors' => '[EWS] tenantId is empty or not a string'];
        } elseif (!Validator::notEmpty()->stringType()->validate($args['clientId'])) {
            return ['errors' => '[EWS] clientId is empty or not a string'];
        } elseif (!Validator::notEmpty()->stringType()->validate($args['clientSecret'])) {
            return ['errors' => '[EWS] clientSecret is empty or not a string'];
        }

        return true;
    }

    public static function getAttachments(array $args)
    {
        ValidatorModel::notEmpty($args, ['attachmentIds', 'emailId', 'config', 'resId']);
        ValidatorModel::arrayType($args, ['attachmentIds', 'config']);
        ValidatorModel::stringType($args, ['emailId']);
        ValidatorModel::intVal($args, ['resId']);

        $client = EWSController::initOauth2([
            'ewsHost'       => $args['config']['ewsHost'] ?? null,
            'email'         => $args['config']['email'] ?? null,
            'version'       => $args['config']['version'] ?? null,
            'tenantId'      => $args['config']['tenantId'] ?? null,
            'clientId'      => $args['config']['clientId'] ?? null,
            'clientSecret'  => $args['config']['clientSecret'] ?? null
        ]);

        if (!empty($client['errors'])) {
            return ['errors' => $client['errors'], 'lang' => $client['lang']];
        }
        $client = $client['client'];

        // Some fixes on the message id from outlook js API, seen at :
        // https://blog.mastykarz.nl/office-365-unified-api-mail/
        $args['emailId'] = str_replace( '-', '/', $args['emailId'] );
        $args['emailId'] = str_replace( '_', '+', $args['emailId'] );

        // Build the get item request.
        $request = new GetItemType();
        $request->ItemShape = new ItemResponseShapeType();
        $request->ItemShape->BaseShape = DefaultShapeNamesType::ALL_PROPERTIES;
        $request->ItemIds = new NonEmptyArrayOfBaseItemIdsType();

        // Add the message id to the request.
        $item = new ItemIdType();
        $item->Id = $args['emailId'];
        $request->ItemIds->ItemId[] = $item;

        try {
            $response = $client->GetItem($request);
        } catch (\Exception $e) {
            if ($e->getCode() == 401) {
                LogsController::add([
                    'isTech'    => true,
                    'moduleId'  => 'EWSController',
                    'level'     => 'ERROR',
                    'recordId'  => 'EWS OAuth2 Error',
                    'eventType' => 'Curl',
                    'eventId'   => 'Error while fetching mail data, response: ' . $e->getMessage()
                ]);
                return ['errors' => 'Error while fetching mail data: ' . $e->getMessage() , 'lang' => 'outlookGetMailDataImpossible'];
            }
            BatchHistoryModel::create(['info' => 'Get outlook attachments error :' . $e->getMessage(), 'module_name' => 'outlook']);
            LogsController::add([
                'isTech'    => true,
                'moduleId'  => 'EWSController',
                'level'     => 'ERROR',
                'recordId'  => 'EWS OAuth2 Error',
                'eventType' => 'Curl',
                'eventId'   => 'Error when getting attachments. Exception: ' . $e->getMessage()
            ]);
            return ['errors' => 'Error when getting attachments. Exception: ' . $e->getMessage(), 'lang' => 'outlookGetAttachmentsImpossible'];
        }
        // Iterate over the results, printing any error messages or receiving attachments.
        $responseMessages = $response->ResponseMessages->GetItemResponseMessage;

        $errors = [];

        foreach ($responseMessages as $responseMessage) {
            // Make sure the request succeeded.
            if ($responseMessage->ResponseClass != ResponseClassType::SUCCESS) {
                $errors[] = 'Failed to get attachments list : '.$responseMessage->MessageText.' (' . $responseMessage->ResponseCode . ')';
                continue;
            }

            // Iterate over the messages, getting the attachments for each.
            $attachments = array();
            foreach ($responseMessage->Items->Message as $item) {
                // If there are no attachments for the item, move on to the next message.
                if (empty($item->Attachments)) {
                    continue;
                }

                // Iterate over the attachments for the message.
                foreach ($item->Attachments->FileAttachment as $attachment) {
                    // Filter only the attachments we want to get
                    if (in_array($attachment->AttachmentId->Id, $args['attachmentIds'])) {
                        $attachments[] = $attachment->AttachmentId->Id;
                    }
                }
            }

            if (empty($attachments)) {
                $errors[] = 'No attachments found';
                continue;
            }

            // Build the request to get the attachments.
            $request = new GetAttachmentType();
            $request->AttachmentIds = new NonEmptyArrayOfRequestAttachmentIdsType();

            // Iterate over the attachments for the message.
            foreach ( $attachments as $attachment_id ) {
                $id = new RequestAttachmentIdType();
                $id->Id = $attachment_id;
                $request->AttachmentIds->AttachmentId[] = $id;
            }

            $response = $client->GetAttachment($request);

            // Iterate over the response messages, printing any error messages or
            // saving the attachments.
            $attachmentResponseMessages = $response->ResponseMessages->GetAttachmentResponseMessage;
            foreach ($attachmentResponseMessages as $attachmentResponseMessage) {
                // Make sure the request succeeded.
                if ($attachmentResponseMessage->ResponseClass != ResponseClassType::SUCCESS) {
                    $errors[] = 'Failed to get attachment : '.$responseMessage->MessageText.' (' . $responseMessage->ResponseCode . ')';
                    continue;
                }

                // Iterate over the file attachments, saving each one.
                $attachments = $attachmentResponseMessage->Attachments->FileAttachment;
                foreach ($attachments as $attachment) {
                    $format = pathinfo($attachment->Name, PATHINFO_EXTENSION);
                    $store = StoreController::storeAttachment([
                        'encodedFile' => base64_encode($attachment->Content),
                        'title'       => $attachment->Name,
                        'type'        => $args['config']['attachmentType'],
                        'resIdMaster' => $args['resId'],
                        'format'      => $format
                    ]);
                    if (!empty($store['errors'])) {
                        $errors[] = 'Failed to store attachment : ' . $store['errors'];
                        continue;
                    }
                    ConvertPdfController::convert([
                        'resId'     => $store,
                        'collId'    => 'attachments_coll'
                    ]);
                }
            }
        }

        if (!empty($errors)) {
            LogsController::add([
                'isTech'    => true,
                'moduleId'  => 'EWSController',
                'level'     => 'ERROR',
                'recordId'  => 'Attachment Errors',
                'eventType' => '',
                'eventId'   => json_encode($errors)
            ]);
        }

        return $errors;
    }
}
