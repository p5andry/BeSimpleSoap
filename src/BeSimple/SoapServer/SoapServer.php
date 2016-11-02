<?php

/*
 * This file is part of the BeSimpleSoapServer.
 *
 * (c) Christian Kerl <christian-kerl@web.de>
 * (c) Francis Besset <francis.besset@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace BeSimple\SoapServer;

use BeSimple\SoapCommon\SoapKernel;
use BeSimple\SoapCommon\SoapOptions\SoapOptions;
use BeSimple\SoapCommon\SoapRequest;
use BeSimple\SoapCommon\SoapRequestFactory;
use BeSimple\SoapServer\SoapOptions\SoapServerOptions;
use BeSimple\SoapCommon\Converter\MtomTypeConverter;
use BeSimple\SoapCommon\Converter\SwaTypeConverter;
use Exception;

/**
 * Extended SoapServer that allows adding filters for SwA, MTOM, ... .
 *
 * @author Andreas Schamberger <mail@andreass.net>
 * @author Christian Kerl <christian-kerl@web.de>
 * @author Petr Bechyně <petr.bechyne@vodafone.com>
 */
class SoapServer extends \SoapServer
{
    const SOAP_SERVER_REQUEST_FAILED = false;

    protected $soapVersion;
    protected $soapServerOptions;
    protected $soapOptions;

    /**
     * Constructor.
     *
     * @param SoapServerOptions $soapServerOptions
     * @param SoapOptions $soapOptions
     */
    public function __construct(SoapServerOptions $soapServerOptions, SoapOptions $soapOptions)
    {
        if ($soapOptions->hasAttachments()) {
            $soapOptions = $this->configureTypeConverters($soapOptions);
        }
        $this->soapVersion = $soapOptions->getSoapVersion();
        $this->soapServerOptions = $soapServerOptions;
        $this->soapOptions = $soapOptions;

        parent::__construct(
            $soapOptions->getWsdlFile(),
            $soapServerOptions->toArray() + $soapOptions->toArray()
        );
    }

    /**
     * Custom handle method to be able to modify the SOAP messages.
     *
     * @param string $requestUrl
     * @param string $soapAction
     * @param string $requestContent = null
     * @return string|false
     */
    public function handle($requestUrl, $soapAction, $requestContent = null)
    {
        try {

            return $this->getSoapResponse($requestUrl, $soapAction, $requestContent)->getResponseContent();

        } catch (\SoapFault $fault) {
            $this->fault($fault->faultcode, $fault->faultstring);

            return self::SOAP_SERVER_REQUEST_FAILED;
        }
    }

    /**
     * Custom handle method to be able to modify the SOAP messages.
     *
     * @param string $requestUrl
     * @param string $soapAction
     * @param string $requestContent = null
     * @return SoapResponse
     */
    public function getSoapResponse($requestUrl, $soapAction, $requestContent = null)
    {
        $soapRequest = SoapRequestFactory::create(
            $requestUrl,
            $soapAction,
            $this->soapVersion,
            $requestContent
        );
        $soapResponse = $this->handleSoapRequest($soapRequest);

        return $soapResponse;
    }

    /**
     * Runs the currently registered request filters on the request, calls the
     * necessary functions (through the parent's class handle()) and runs the
     * response filters.
     *
     * @param SoapRequest $soapRequest SOAP request object
     *
     * @return SoapResponse
     */
    private function handleSoapRequest(SoapRequest $soapRequest)
    {
        $soapKernel = new SoapKernel();
        if ($this->soapOptions->hasAttachments()) {
            $soapRequest = $soapKernel->filterRequest($soapRequest, $this->getFilters(), $this->soapOptions->getAttachmentType());
        }

        ob_start();
        parent::handle($soapRequest->getContent());
        $response = ob_get_clean();

        // Remove headers added by SoapServer::handle() method
        header_remove('Content-Length');
        header_remove('Content-Type');

        $soapResponse = SoapResponseFactory::create(
            $response,
            $soapRequest->getLocation(),
            $soapRequest->getAction(),
            $soapRequest->getVersion()
        );

        if ($this->soapOptions->hasAttachments()) {
            $soapResponse = $soapKernel->filterResponse($soapResponse, $this->getFilters(), $this->soapOptions->getAttachmentType());
        }

        return $soapResponse;
    }

    private function configureTypeConverters(SoapOptions $soapOptions)
    {
        if ($soapOptions->getAttachmentType() !== SoapOptions::SOAP_ATTACHMENTS_TYPE_BASE64) {
            if ($soapOptions->getAttachmentType() === SoapOptions::SOAP_ATTACHMENTS_TYPE_SWA) {
                $soapOptions->getTypeConverterCollection()->add(new SwaTypeConverter());
            } elseif ($soapOptions->getAttachmentType() === SoapOptions::SOAP_ATTACHMENTS_TYPE_MTOM) {
                $soapOptions->getTypeConverterCollection()->add(new MtomTypeConverter());
            } else {
                throw new Exception('Unresolved SOAP_ATTACHMENTS_TYPE: ' . $soapOptions->getAttachmentType());
            }
        }

        return $soapOptions;
    }

    private function getFilters()
    {
        $filters = [];
        if ($this->soapOptions->getAttachmentType() !== SoapOptions::SOAP_ATTACHMENTS_TYPE_BASE64) {
            $filters[] = new MimeFilter();
        }
        if ($this->soapOptions->getAttachmentType() === SoapOptions::SOAP_ATTACHMENTS_TYPE_MTOM) {
            $filters[] = new XmlMimeFilter();
        }

        return $filters;
    }
}
