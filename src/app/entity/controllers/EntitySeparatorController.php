<?php

/**
* Copyright Maarch since 2008 under licence GPLv3.
* See LICENCE.txt file at the root folder for more details.
* This file is part of Maarch software.
*
*/

/**
* @brief Entity Separator Controller
* @author dev@maarch.org
*/

namespace Entity\controllers;

use Com\Tecnick\Barcode\Barcode;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Entity\models\EntityModel;
use Group\controllers\PrivilegeController;
use Parameter\models\ParameterModel;
use Respect\Validation\Validator;
use setasign\Fpdi\Tcpdf\Fpdi;
use Slim\Psr7\Request;
use SrcCore\http\Response;
use SrcCore\models\CoreConfigModel;

class EntitySeparatorController
{
    public function create(Request $request, Response $response)
    {
        if (!PrivilegeController::hasPrivilege(['privilegeId' => 'entities_print_sep_mlb', 'userId' => $GLOBALS['id']])) {
            return $response->withStatus(403)->withJson(['errors' => 'Service forbidden']);
        }

        $body = $request->getParsedBody();

        if (!Validator::stringType()->notEmpty()->validate($body['type'])) {
            return $response->withStatus(400)->withJson(['errors' => 'Body type is not set or empty']);
        } elseif (!in_array($body['type'], ['barcode', 'qrcode'])) {
            return $response->withStatus(400)->withJson(['errors' => 'Body type value must be qrcode or barcode']);
        }

        $entitiesList = [];
        if ($body['target'] == 'generic') {
            $entitiesList['GENERIQUE'] = 'Maarch Courrier';
        } else {
            if (!Validator::arrayType()->notEmpty()->validate($body['entities'])) {
                return $response->withStatus(400)->withJson(['errors' => 'Body entities is not set or empty']);
            }

            $entitiesInfo = EntityModel::get([
                'select'   => ['entity_label', 'id'],
                'where'    => ['entity_id in (?)'],
                'data'     => [$body['entities']],
                'order_by' => ['entity_label asc']
            ]);

            foreach ($entitiesInfo as $value) {
                $entitiesList[$value['id']] = $value['entity_label'];
            }
        }

        $libPath = CoreConfigModel::getFpdiPdfParserLibrary();
        if (file_exists($libPath)) {
            require_once($libPath);
        }
        $pdf = new Fpdi('P', 'pt');
        $pdf->setPrintHeader(false);

        $prefix = '';
        if ($body['type'] == 'qrcode') {
            $parameter = ParameterModel::getById(['select' => ['param_value_int'], 'id' => 'QrCodePrefix']);
            if ($parameter['param_value_int'] == 1) {
                $prefix = 'MAARCH_';
            }
        }
        foreach ($entitiesList as $entityId => $entityLabel) {
            $pdf->AddPage();
            $pdf->SetFont('', 'B', 25);

            if ($body['type'] == 'qrcode') {
                $qrCode = new QrCode($prefix . $entityId);
                $qrCode->setSize(600);

                $pngWriter = new PngWriter();
                $qrCodeResult = $pngWriter->write($qrCode);
                $qrCodeResult = $qrCodeResult->getString();

                $pdf->Image('@' . $qrCodeResult, 0, 40, 200, '', '', '', '', false, 300, 'C');
            } else {
                $barcode = new Barcode();
                $bobj = $barcode->getBarcodeObj(
                    'C128',                     // barcode type and additional comma-separated parameters
                    $prefix . $entityId,        // data string to encode
                    -4,                         // bar width (use absolute or negative value as multiplication factor)
                    -100,                         // bar height (use absolute or negative value as multiplication factor)
                    'black',                    // foreground color
                    [-2, -2, -2, -2]       // padding (use absolute or negative values as multiplication factors)
                )->setBackgroundColor('white'); // background color

                $pdf->Image('@'.$bobj->getPngData(), 0, 40, 200, '', '', '', '', false, 300, 'C');
            }

            $pdf->SetY($pdf->GetY() + 300);
            $pdf->Cell(0, 20, _PRINT_SEP_TITLE, 0, 2, 'C', false);
            $pdf->SetY($pdf->GetY() + 60);
            $pdf->Cell(0, 30, _ENTITY, 1, 1, 'C');
            $pdf->SetFont('', 'B', 12);
            $pdf->Cell(0, 30, $entityLabel . ' (' . $prefix . $entityId . ')', 1, 1, 'C');
        }

        $fileContent = $pdf->Output('', 'S');

        return $response->withJson(base64_encode($fileContent));
    }
}
