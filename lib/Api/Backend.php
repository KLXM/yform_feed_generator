<?php

namespace KLXM\YFormFeedGenerator\Api;

use KLXM\YFormFeedGenerator\Links;
use KLXM\YFormFeedGenerator\Schema;
use rex;
use rex_api_exception;
use rex_api_function;
use rex_api_result;
use rex_response;

/**
 * AJAX für das Mapping im Backend. Nur mit Backend-Sitzung (nicht published), eigenem Recht und CSRF-Token.
 * Liefert ausschließlich Strukturinformationen (Felder, Relationswerte), keine Inhalte der Datensätze.
 */
final class Backend extends rex_api_function
{
    protected function requiresCsrfProtection(): bool
    {
        return true;
    }

    public function execute(): rex_api_result
    {
        $user = rex::getUser();
        if (null === $user || !$user->hasPerm('yform_feed_generator[]')) {
            throw new rex_api_exception('Keine Berechtigung.');
        }

        $action = rex_request('action', 'string', '');
        $table = rex_request('table', 'string', '');
        if (!Schema::isTable($table)) {
            $this->send(['error' => 'Unbekannte Tabelle.'], rex_response::HTTP_BAD_REQUEST);
        }

        switch ($action) {
            case 'fields':
                $this->send([
                    'fields' => Schema::fields($table),
                    'profiles' => Links::profiles($table),
                ]);
                // no break
            case 'options':
                $field = rex_request('field', 'string', '');
                $this->send(['options' => Schema::relationOptions($table, $field)]);
                // no break
            default:
                $this->send(['error' => 'Unbekannte Aktion.'], rex_response::HTTP_BAD_REQUEST);
        }
    }

    /** @param array<string, mixed> $data */
    private function send(array $data, string $status = rex_response::HTTP_OK): never
    {
        rex_response::cleanOutputBuffers();
        rex_response::setStatus($status);
        rex_response::setHeader('Cache-Control', 'no-store');
        rex_response::sendJson($data);
        exit;
    }
}
