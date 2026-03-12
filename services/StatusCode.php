<?php

class StatusCode
{
    public static function fromScore(int $score): array
    {
        if ($score >= 90) {
            return ['EXCELLENT', 'STRONG & STABLE'];
        }

        if ($score >= 80) {
            return ['GOOD', 'MINOR IMPROVEMENTS'];
        }

        if ($score >= 60) {
            return ['WARNING', 'NEEDS ATTENTION'];
        }

        return ['CRITICAL', 'IMMEDIATE ACTION REQUIRED'];
    }
}
