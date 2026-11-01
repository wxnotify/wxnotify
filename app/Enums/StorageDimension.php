<?php

namespace App\Enums;

enum StorageDimension: string
{
    case Source = 'source';
    case CatalogGroup = 'catalog_group';
    case FileKind = 'file_kind';
}
