<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class WhiteScreenDiagTest extends TestCase
{
    use CreatesOrganizations, RefreshDatabase;

    public function test_dump_pages(): void
    {
        ['organization' => $org, 'roles' => $roles] = $this->makeOrganization();
        $user = $this->makeUser($org, 'company_admin', $roles);

        foreach (['/dashboard/admin', '/employees', '/'] as $path) {
            $r = $this->actingAs($user)->get($path);
            $code = $r->getStatusCode();
            $content = $r->getContent();
            $out = __DIR__.'/../../storage/framework/diag'.str_replace('/', '_', $path).'.html';
            file_put_contents($out, $content);
            echo "\nPATH=$path status=$code len=".strlen($content)."\n";
            if ($code !== 200) {
                echo substr(strip_tags($content), 0, 500)."\n";
            }
        }
        $this->assertTrue(true);
    }
}