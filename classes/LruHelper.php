<?php
class LruHelper
{
    /**
     * Check if the current logged-in employee is a super admin (profile ID 1)
     *
     * @return bool
     */
    public static function isSuperAdmin()
    {
        $context = Context::getContext();
        if (!isset($context->employee)) {
            return false;
        }
        return $context->employee->id_profile == 1;
    }
}
