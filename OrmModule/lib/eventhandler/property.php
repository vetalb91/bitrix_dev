<?php

namespace modlam\Orm\EventHandler;


class Property
{
    public static function OnAfterIBlockPropertyAdd($arFields)
    {
        static::clearTagCacheByPropertyId($arFields["ID"]);
    }

    public static function OnAfterIBlockPropertyUpdate($arFields)
    {
        static::clearTagCacheByPropertyId($arFields["ID"]);
    }

    public static function OnBeforeIBlockPropertyDelete($ID)
    {
        static::clearTagCacheByPropertyId($ID);
    }

    private static function clearTagCacheByPropertyId($ID)
    {
        global $CACHE_MANAGER;

        if ($ID > 0)
        {
            if ($arProperty = \CIBlockProperty::GetByID($ID)->Fetch())
            {
                $CACHE_MANAGER->ClearByTag("iblock_id_" . $arProperty["IBLOCK_ID"]);
            }
        }
    }
}