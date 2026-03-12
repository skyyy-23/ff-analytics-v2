<?php

class InterpretationCode
{
    public static function fromScore(int $score): array
    {
        if ($score >= 90) {
            return [
                'This branch is operating at an excellent level.',
                'Sustain strategies and optimize for long-term growth.',
            ];
        }

        if ($score >= 80) {
            return [
                'This branch is healthy with minor improvement areas.',
                'Address weaker metrics to maximize performance.',
            ];
        }

        if ($score >= 60) {
            return [
                'This branch requires management attention.',
                'Operational inefficiencies should be addressed.',
            ];
        }

        return [
            'This branch is in critical condition.',
            'Immediate corrective actions are required.',
        ];
    }
}
