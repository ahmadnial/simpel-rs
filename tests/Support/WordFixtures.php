<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

trait WordFixtures
{
    private function wordUpload(string $body = '<w:p><w:r><w:t>Naskah</w:t></w:r></w:p>', string $name = 'naskah.docx', array $parts = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'simpel_word_');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="18720" w:h="12240" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/></w:sectPr></w:body></w:document>');
        foreach ($parts as $part => $xml) {
            $zip->addFromString($part, $xml);
        }
        $zip->close();
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }
}
