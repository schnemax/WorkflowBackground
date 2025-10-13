<?php
namespace App\Sepa;

interface SepaService {
    /** return Pfad zur erzeugten Datei oder null bei Fehler */
    public function generate_sepa_pain001(array $payment, int $docId, $repo): ?string;
}
