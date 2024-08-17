<?php

/**
 * Copyright Maarch since 2008 under licence GPLv3.
 * See LICENCE.txt file at the root folder for more details.
 * This file is part of Maarch software.
 */

/**
 * @brief Online Editor Controller
 *
 * @author dev@maarch.org
 */

namespace ContentManagement\controllers;

use Configuration\models\ConfigurationModel;
use Slim\Psr7\Request;
use SrcCore\http\Response;
use SrcCore\models\ValidatorModel;
use Respect\Validation\Validator;

class DocumentEditorController
{
    const DOCUMENT_EDITION_METHODS = ['java', 'onlyoffice', 'collaboraonline', 'office365sharepoint'];

    public static function get(Request $request, Response $response)
    {
        $allowedMethods = DocumentEditorController::getAllowedMethods();

        return $response->withJson($allowedMethods);
    }

    public static function getAllowedMethods()
    {
        $configuration = ConfigurationModel::getByPrivilege(['privilege' => 'admin_document_editors', 'select' => ['value']]);
        $configuration = !empty($configuration['value']) ? json_decode($configuration['value'], true) : [];

        $allowedMethods = [];
        foreach ($configuration as $key => $method) {
            $allowedMethods[] = $key;
        }

        return $allowedMethods;
    }

    public static function isAvailable(array $args)
    {
        ValidatorModel::notEmpty($args, ['uri', 'port']);
        ValidatorModel::stringType($args, ['uri']);
        ValidatorModel::intType($args, ['port']);

        $uri = $args['uri'] ?? null;

        if (!DocumentEditorController::uriIsValid($uri)) {
            return ['errors' => "Editor 'uri' is not a valid URL or IP address format", 'lang' => 'editorHasNoValidUrlOrIp'];
        }

        $aUri = explode("/", $args['uri']);
        $exec = shell_exec("nc -vz -w 5 {$aUri[0]} {$args['port']} 2>&1");

        if (strpos($exec, 'not found') !== false) {
            return ['errors' => 'Netcat command not found', 'lang' => 'preRequisiteMissing'];
        }

        return strpos($exec, 'succeeded!') !== false || strpos($exec, 'open') !== false || strpos($exec, 'Connected') !== false;
    }

    public static function uriIsValid($args): ?bool {
        $whitelist = '/^(?:\w+(?:\/)?|(?:https?:\/\/)?((?:[\da-z.-]+)\.(?:[a-z.]{2,6})|(?:\d{1,3}\.){3}\d{1,3})(?:[\/\w.-]*)*\/?)$/i';
        return preg_match($whitelist, $args);
    }
}
