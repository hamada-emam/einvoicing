<?php
namespace Einvoicing\Readers;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Delivery;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Writers\UblWriter;
use InvalidArgumentException;
use UXML\UXML;
use function array_filter;
use function array_map;

class UblReader extends AbstractReader {
    /**
     * @inheritdoc
     * @throws InvalidArgumentException if failed to parse XML
     */
    public function import(string $document): Invoice {
        $invoice = new Invoice();

        // Load XML document
        $xml = UXML::fromString($document);
        $cac = UblWriter::NS_CAC;
        $cbc = UblWriter::NS_CBC;

        // BT-24: Specification indentifier
        $specificationNode = $xml->get("{{$cbc}}CustomizationID");
        if ($specificationNode !== null) {
            $specification = $specificationNode->asText();
            $invoice->setSpecification($specification);

            // Try to create from preset
            $presetClassname = $this->getPresetFromSpecification($specification);
            if ($presetClassname !== null) {
                $invoice = new Invoice($presetClassname);
            }
        }

        // BT-23: Business process type
        $businessProcessNode = $xml->get("{{$cbc}}ProfileID");
        if ($businessProcessNode !== null) {
            $invoice->setBusinessProcess($businessProcessNode->asText());
        }

        // BT-1: Invoice number
        $numberNode = $xml->get("{{$cbc}}ID");
        if ($numberNode !== null) {
            $invoice->setNumber($numberNode->asText());
        }

        // BT-2: Issue date
        $issueDateNode = $xml->get("{{$cbc}}IssueDate");
        if ($issueDateNode !== null) {
            $invoice->setIssueDate(new DateTime($issueDateNode->asText()));
        }

        // BT-9: Due date
        $dueDateNode = $xml->get("{{$cbc}}DueDate");
        if ($dueDateNode !== null) {
            $invoice->setDueDate(new DateTime($dueDateNode->asText()));
        }

        // BT-3: Invoice type code
        $typeNode = $xml->get("{{$cbc}}InvoiceTypeCode");
        if ($typeNode !== null) {
            $invoice->setType((int) $typeNode->asText());
        }

        // BT-22: Note
        $noteNode = $xml->get("{{$cbc}}Note");
        if ($noteNode !== null) {
            $invoice->setNote($noteNode->asText());
        }

        // BT-7: Tax point date
        $taxPointDateNode = $xml->get("{{$cbc}}TaxPointDate");
        if ($taxPointDateNode !== null) {
            $invoice->setTaxPointDate(new DateTime($taxPointDateNode->asText()));
        }

        // BT-5: Invoice currency code
        $currencyNode = $xml->get("{{$cbc}}DocumentCurrencyCode");
        if ($currencyNode !== null) {
            $invoice->setCurrency($currencyNode->asText());
        }

        // BT-19: Buyer accounting reference
        $buyerAccountingReferenceNode = $xml->get("{{$cbc}}AccountingCost");
        if ($buyerAccountingReferenceNode !== null) {
            $invoice->setBuyerAccountingReference($buyerAccountingReferenceNode->asText());
        }

        // BT-10: Buyer reference
        $buyerReferenceNode = $xml->get("{{$cbc}}BuyerReference");
        if ($buyerReferenceNode !== null) {
            $invoice->setBuyerReference($buyerReferenceNode->asText());
        }

        // Seller node
        $sellerNode = $xml->get("{{$cac}}AccountingSupplierParty/{{$cac}}Party");
        if ($sellerNode !== null) {
            $invoice->setSeller($this->parseSellerOrBuyerNode($sellerNode));
        }

        // Buyer node
        $buyerNode = $xml->get("{{$cac}}AccountingCustomerParty/{{$cac}}Party");
        if ($buyerNode !== null) {
            $invoice->setBuyer($this->parseSellerOrBuyerNode($buyerNode));
        }

        // Payee node
        $payeeNode = $xml->get("{{$cac}}PayeeParty");
        if ($payeeNode !== null) {
            $invoice->setPayee($this->parsePayeeNode($payeeNode));
        }

        // Delivery node
        $deliveryNode = $xml->get("{{$cac}}Delivery");
        if ($deliveryNode !== null) {
            $invoice->setDelivery($this->parseDeliveryNode($deliveryNode));
        }

        // Allowances and charges
        foreach ($xml->getAll("{{$cac}}AllowanceCharge") as $node) {
            $this->addAllowanceOrCharge($invoice, $node);
        }

        // Invoice lines
        foreach ($xml->getAll("{{$cac}}InvoiceLine") as $node) {
            $invoice->addLine($this->parseInvoiceLine($node));
        }

        return $invoice;
    }


    /**
     * Parse identifier node
     * @param  UXML       $xml        XML node
     * @param  string     $schemeAttr Scheme attribute name
     * @return Identifier             Identifier instance
     */
    private function parseIdentifierNode(UXML $xml, string $schemeAttr="schemeID"): Identifier {
        $value = $xml->asText();
        $scheme = $xml->element()->hasAttribute($schemeAttr) ? $xml->element()->getAttribute($schemeAttr) : null;
        return new Identifier($value, $scheme);
    }


    /**
     * Parse postal address fields
     * @param UXML           $xml    XML node
     * @param Delivery|Party $target Destination instance
     */
    private function parsePostalAddressFields(UXML $xml, $target) {
        $cac = UblWriter::NS_CAC;
        $cbc = UblWriter::NS_CBC;

        // Postal address
        $addressNodes = array_filter([
            $xml->get("{{$cbc}}StreetName"),
            $xml->get("{{$cbc}}AdditionalStreetName"),
            $xml->get("{{$cac}}AddressLine/{{$cbc}}Line")
        ]);
        $addressLines = array_map(function($node) {
            return $node->asText();
        }, $addressNodes);
        // @phan-suppress-next-line PhanThrowTypeAbsentForCall
        $target->setAddress($addressLines);

        // City name
        $cityNode = $xml->get("{{$cbc}}CityName");
        if ($cityNode !== null) {
            $target->setCity($cityNode->asText());
        }

        // Postal code
        $postalCodeNode = $xml->get("{{$cbc}}PostalZone");
        if ($postalCodeNode !== null) {
            $target->setPostalCode($postalCodeNode->asText());
        }

        // Subdivision
        $subdivisionNode = $xml->get("{{$cbc}}CountrySubentity");
        if ($subdivisionNode !== null) {
            $target->setSubdivision($subdivisionNode->asText());
        }

        // Country
        $countryNode = $xml->get("{{$cac}}Country/{{$cbc}}IdentificationCode");
        if ($countryNode !== null) {
            $target->setCountry($countryNode->asText());
        }
    }


    /**
     * Parse seller or buyer node
     * @param  UXML  $xml XML node
     * @return Party      Party instance
     */
    private function parseSellerOrBuyerNode(UXML $xml): Party {
        $party = new Party();
        $cac = UblWriter::NS_CAC;
        $cbc = UblWriter::NS_CBC;

        // Electronic address
        $electronicAddressNode = $xml->get("{{$cbc}}EndpointID");
        if ($electronicAddressNode !== null) {
            $party->setElectronicAddress($this->parseIdentifierNode($electronicAddressNode));
        }

        // Additional identifiers
        foreach ($xml->getAll("{{$cac}}PartyIdentification/{{$cbc}}ID") as $identifierNode) {
            $party->addIdentifier($this->parseIdentifierNode($identifierNode));
        }

        // Trading name
        $tradingNameNode = $xml->get("{{$cac}}PartyName/{{$cbc}}Name");
        if ($tradingNameNode !== null) {
            $party->setTradingName($tradingNameNode->asText());
        }

        // Postal address
        $addressNode = $xml->get("{{$cac}}PostalAddress");
        if ($addressNode !== null) {
            $this->parsePostalAddressFields($addressNode, $party);
        }

        // VAT number
        $vatNumberNode = $xml->get("{{$cac}}PartyTaxScheme/{{$cbc}}CompanyID");
        if ($vatNumberNode !== null) {
            $party->setVatNumber($vatNumberNode->asText());
        }

        // Legal name
        $legalNameNode = $xml->get("{{$cac}}PartyLegalEntity/{{$cbc}}RegistrationName");
        if ($legalNameNode !== null) {
            $party->setName($legalNameNode->asText());
        }
        
        // Company ID
        $companyIdNode = $xml->get("{{$cac}}PartyLegalEntity/{{$cbc}}CompanyID");
        if ($companyIdNode !== null) {
            $party->setCompanyId($this->parseIdentifierNode($companyIdNode));
        }

        // Contact name
        $contactNameNode = $xml->get("{{$cac}}Contact/{{$cbc}}Name");
        if ($contactNameNode !== null) {
            $party->setContactName($contactNameNode->asText());
        }

        // Contact phone
        $contactPhoneNode = $xml->get("{{$cac}}Contact/{{$cbc}}Telephone");
        if ($contactPhoneNode !== null) {
            $party->setContactPhone($contactPhoneNode->asText());
        }

        // Contact email
        $contactEmailNode = $xml->get("{{$cac}}Contact/{{$cbc}}ElectronicMail");
        if ($contactEmailNode !== null) {
            $party->setContactEmail($contactEmailNode->asText());
        }

        return $party;
    }


    /**
     * Parse payee node
     * @param  UXML  $xml XML node
     * @return Party      Party instance
     */
    private function parsePayeeNode(UXML $xml): Party {
        $party = new Party();
        $cac = UblWriter::NS_CAC;
        $cbc = UblWriter::NS_CBC;

        // Additional identifiers
        foreach ($xml->getAll("{{$cac}}PartyIdentification/{{$cbc}}ID") as $identifierNode) {
            $party->addIdentifier($this->parseIdentifierNode($identifierNode));
        }

        // Party name
        $nameNode = $xml->get("{{$cac}}PartyName/{{$cbc}}Name");
        if ($nameNode !== null) {
            $party->setName($nameNode->asText());
        }

        // Company ID
        $companyIdNode = $xml->get("{{$cac}}PartyLegalEntity/{{$cbc}}CompanyID");
        if ($companyIdNode !== null) {
            $party->setCompanyId($this->parseIdentifierNode($companyIdNode));
        }

        return $party;
    }


    /**
     * Parse delivery node
     * @param  UXML     $xml XML node
     * @return Delivery      Delivery instance
     */
    private function parseDeliveryNode(UXML $xml): Delivery {
        $delivery = new Delivery();
        $cac = UblWriter::NS_CAC;
        $cbc = UblWriter::NS_CBC;

        // BT-72: Actual delivery date
        $dateNode = $xml->get("{{$cbc}}ActualDeliveryDate");
        if ($dateNode !== null) {
            $delivery->setDate(new DateTime($dateNode->asText()));
        }

        // BT-71: Delivery location identifier
        $locationIdentifierNode = $xml->get("{{$cac}}DeliveryLocation/{{$cbc}}ID");
        if ($locationIdentifierNode !== null) {
            $delivery->setLocationIdentifier($this->parseIdentifierNode($locationIdentifierNode));
        }

        // Delivery postal address
        $addressNode = $xml->get("{{$cac}}DeliveryLocation/{{$cac}}Address");
        if ($addressNode !== null) {
            $this->parsePostalAddressFields($addressNode, $delivery);
        }

        // BT-70: Deliver name
        $nameNode = $xml->get("{{$cac}}DeliveryParty/{{$cac}}PartyName/{{$cbc}}Name");
        if ($nameNode !== null) {
            $delivery->setName($nameNode->asText());
        }

        return $delivery;
    }


    /**
     * Set VAT attributes
     * @param AllowanceOrCharge|InvoiceLine $target Target instance
     * @param UXML                          $xml    XML node
     */
    private function setVatAttributes($target, UXML $xml) {
        $cbc = UblWriter::NS_CBC;

        // Tax category
        $taxCategoryNode = $xml->get("{{$cbc}}ID");
        if ($taxCategoryNode !== null) {
            $target->setVatCategory($taxCategoryNode->asText());
        }

        // Tax rate
        $taxRateNode = $xml->get("{{$cbc}}Percent");
        if ($taxRateNode !== null) {
            $target->setVatRate((float) $taxRateNode->asText());
        }
    }


    /**
     * Add allowance or charge
     * @param Invoice|InvoiceLine $target Target instance
     * @param UXML                $xml    XML node
     */
    private function addAllowanceOrCharge($target, UXML $xml) {
        $allowanceOrCharge = new AllowanceOrCharge();
        $cac = UblWriter::NS_CAC;
        $cbc = UblWriter::NS_CBC;

        // Add instance to invoice
        if ($xml->get("{{$cbc}}ChargeIndicator")->asText() === "true") {
            $target->addCharge($allowanceOrCharge);
        } else {
            $target->addAllowance($allowanceOrCharge);
        }

        // Reason code
        $reasonCodeNode = $xml->get("{{$cbc}}AllowanceChargeReasonCode");
        if ($reasonCodeNode !== null) {
            $allowanceOrCharge->setReasonCode($reasonCodeNode->asText());
        }

        // Reason text
        $reasonTextNode = $xml->get("{{$cbc}}AllowanceChargeReason");
        if ($reasonTextNode !== null) {
            $allowanceOrCharge->setReason($reasonTextNode->asText());
        }

        // Amount
        $factorNode = $xml->get("{{$cbc}}MultiplierFactorNumeric");
        if ($factorNode === null) {
            $amount = (float) $xml->get("{{$cbc}}Amount")->asText();
            $allowanceOrCharge->setAmount($amount);
        } else {
            $percent = (float) $factorNode->asText();
            $allowanceOrCharge->markAsPercentage()->setAmount($percent);
        }

        // VAT attributes
        $vatNode = $xml->get("{{$cac}}TaxCategory");
        if ($vatNode !== null) {
            $this->setVatAttributes($allowanceOrCharge, $vatNode);
        }
    }


    /**
     * Parse invoice line
     * @param  UXML        $xml XML node
     * @return InvoiceLine      Invoice line instance
     */
    private function parseInvoiceLine(UXML $xml): InvoiceLine {
        $line = new InvoiceLine();
        $cac = UblWriter::NS_CAC;
        $cbc = UblWriter::NS_CBC;

        // Quantity
        $quantityNode = $xml->get("{{$cbc}}InvoicedQuantity");
        if ($quantityNode !== null) {
            $line->setQuantity((float) $quantityNode->asText());
            $line->setUnit($quantityNode->element()->getAttribute('unitCode'));
        }

        // BT-133: Buyer accounting reference
        $buyerAccountingReferenceNode = $xml->get("{{$cbc}}AccountingCost");
        if ($buyerAccountingReferenceNode !== null) {
            $line->setBuyerAccountingReference($buyerAccountingReferenceNode->asText());
        }

        // BT-132: Order line reference
        $orderLineReferenceNode = $xml->get("{{$cac}}OrderLineReference/{{$cbc}}LineID");
        if ($orderLineReferenceNode !== null) {
            $line->setOrderLineReference($orderLineReferenceNode->asText());
        }

        // Allowances and charges
        foreach ($xml->getAll("{{$cac}}AllowanceCharge") as $node) {
            $this->addAllowanceOrCharge($line, $node);
        }

        // BT-154: Item description
        $descriptionNode = $xml->get("{{$cac}}Item/{{$cbc}}Description");
        if ($descriptionNode !== null) {
            $line->setDescription($descriptionNode->asText());
        }

        // BT-153: Item name
        $nameNode = $xml->get("{{$cac}}Item/{{$cbc}}Name");
        if ($nameNode !== null) {
            $line->setName($nameNode->asText());
        }

        // BT-156: Buyer identifier
        $buyerIdentifierNode = $xml->get("{{$cac}}Item/{{$cac}}BuyersItemIdentification/{{$cbc}}ID");
        if ($buyerIdentifierNode !== null) {
            $line->setBuyerIdentifier($buyerIdentifierNode->asText());
        }

        // BT-155: Seller identifier
        $sellerIdentifierNode = $xml->get("{{$cac}}Item/{{$cac}}SellersItemIdentification/{{$cbc}}ID");
        if ($sellerIdentifierNode !== null) {
            $line->setSellerIdentifier($sellerIdentifierNode->asText());
        }

        // BT-157: Standard identifier
        $standardIdentifierNode = $xml->get("{{$cac}}Item/{{$cac}}StandardItemIdentification/{{$cbc}}ID");
        if ($standardIdentifierNode !== null) {
            $line->setStandardIdentifier($this->parseIdentifierNode($standardIdentifierNode));
        }

        // BT-159: Item origin country
        $originCountryNode = $xml->get("{{$cac}}Item/{{$cac}}OriginCountry/{{$cbc}}IdentificationCode");
        if ($originCountryNode !== null) {
            $line->setOriginCountry($originCountryNode->asText());
        }

        // BT-158: Item classification identifiers
        $classNodes = $xml->getAll("{{$cac}}Item/{{$cac}}CommodityClassification/{{$cbc}}ItemClassificationCode");
        foreach ($classNodes as $classNode) {
            $line->addClassificationIdentifier($this->parseIdentifierNode($classNode, 'listID'));
        }

        // Price amount
        $priceNode = $xml->get("{{$cac}}Price/{{$cbc}}PriceAmount");
        if ($priceNode !== null) {
            $line->setPrice((float) $priceNode->asText());
        }

        // Base quantity
        $baseQuantityNode = $xml->get("{{$cac}}Price/{{$cbc}}BaseQuantity");
        if ($baseQuantityNode !== null) {
            $line->setBaseQuantity((float) $baseQuantityNode->asText());
        }

        // VAT attributes
        $vatNode = $xml->get("{{$cac}}Item/{{$cac}}ClassifiedTaxCategory");
        if ($vatNode !== null) {
            $this->setVatAttributes($line, $vatNode);
        }

        return $line;
    }
}
