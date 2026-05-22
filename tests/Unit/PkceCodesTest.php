<?php

use App\Services\OAuth\PkceCodes;

it('derives the code_challenge per RFC 7636 §4.1 test vector', function () {
    // Vector from RFC 7636 Appendix B.
    $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $expectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    expect(PkceCodes::challenge($verifier))->toBe($expectedChallenge);
});

it('generates verifiers within the RFC 7636 length range', function () {
    foreach (range(1, 5) as $_) {
        $verifier = PkceCodes::verifier();

        expect(strlen($verifier))->toBeGreaterThanOrEqual(43);
        expect(strlen($verifier))->toBeLessThanOrEqual(128);
        expect($verifier)->toMatch('/^[A-Za-z0-9\-._~]+$/');
    }
});

it('generates unique verifiers across calls', function () {
    $a = PkceCodes::verifier();
    $b = PkceCodes::verifier();

    expect($a)->not->toBe($b);
});

it('generates state values as hex strings', function () {
    $state = PkceCodes::state();

    expect($state)->toMatch('/^[0-9a-f]+$/');
    expect(strlen($state))->toBe(32);
});

it('outputs base64url challenges with no padding or unsafe chars', function () {
    $challenge = PkceCodes::challenge('a-test-verifier-of-sufficient-length-1234567');

    expect($challenge)->toMatch('/^[A-Za-z0-9\-_]+$/');
    expect($challenge)->not->toContain('=');
    expect($challenge)->not->toContain('+');
    expect($challenge)->not->toContain('/');
});
