<?php
/**
 * This file is part of FPDI
 *
 * @package   setasign\Fpdi
 * @copyright Copyright (c) 2017-2025 Setasign GmbH & Co. KG
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace setasign\Fpdi;

use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObjectReference;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfToken;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfBoolean;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfObjectStream;
use setasign\Fpdi\PdfParser\Type\PdfObjectStreamParser;
use setasign\Fpdi\PdfParser\Type\PdfObjectStreamParserException;
use setasign\Fpdi\PdfParser\Type\PdfObjectStreamParserInterface;
use setasign\Fpdi\PdfParser\Type\PdfObjectStreamParserTrait;
use setasign\Fpdi\PdfParser\Type\PdfParserException;
use setasign\Fpdi\PdfParser\Type\PdfParserInterface;
use setasign\Fpdi\PdfParser\Type\PdfParserTrait;
use setasign\Fpdi\PdfParser\Type\PdfReader;
use setasign\Fpdi\PdfParser\Type\PdfReaderException;
use setasign\Fpdi\PdfParser\Type\PdfReaderInterface;
use setasign\Fpdi\PdfParser\Type\PdfReaderTrait;
use setasign\Fpdi\PdfParser\Type\PdfTokenizer;
use setasign\Fpdi\PdfParser\Type\PdfTokenizerException;
use setasign\Fpdi\PdfParser\Type\PdfTokenizerInterface;
use setasign\Fpdi\PdfParser\Type\PdfTokenizerTrait;
use setasign\Fpdi\PdfParser\Type\PdfTypeExceptionInterface;
use setasign\Fpdi\PdfParser\Type\PdfTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfValue;
use setasign\Fpdi\PdfParser\Type\PdfValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXref;
use setasign\Fpdi\PdfParser\Type\PdfXrefException;
use setasign\Fpdi\PdfParser\Type\PdfXrefInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueType;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueType;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueType;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueType;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueType;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueType;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueType;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueType;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueType;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeTrait;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValue;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueTypeValueInterface;
use setasign\Fpdi\PdfParser\Type\PdfXrefValueTypeValueTypeValueTypeValue
::contentReference[oaicite:0]{index=0}
 
