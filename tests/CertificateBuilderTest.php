<?php

namespace Saleh7\Zatca\Tests;

use PHPUnit\Framework\TestCase;
use Saleh7\Zatca\CertificateBuilder;
use Saleh7\Zatca\Exceptions\CertificateBuilderException;

final class CertificateBuilderTest extends TestCase
{
    /**
     * Test that CertificateBuilder generates and saves CSR and private key.
     */
    public function test_generate_and_save(): void
    {
        // Create CertificateBuilder and set required parameters.
        $builder = new CertificateBuilder;
        $builder->setOrganizationIdentifier('312345678901233')
            ->setSerialNumber('Saleh', '1n', 'SME00023')
            ->setCommonName('My Organization')
            ->setOrganizationName('My Company')
            ->setOrganizationalUnitName('IT Department')
            ->setAddress('Riyadh 1234 Street')
            ->setInvoiceType(1100)
            ->setProduction(true)
            ->setBusinessCategory('Technology');

        // Create temporary files for CSR and private key.
        $csrPath = tempnam(sys_get_temp_dir(), 'csr_');
        $privateKeyPath = tempnam(sys_get_temp_dir(), 'key_');

        // Generate and save CSR and private key.
        $builder->generateAndSave($csrPath, $privateKeyPath);

        // Assert files exist.
        $this->assertFileExists($csrPath, 'CSR file should exist.');
        $this->assertFileExists($privateKeyPath, 'Private key file should exist.');

        // Read file contents.
        $csrContent = file_get_contents($csrPath);
        $keyContent = file_get_contents($privateKeyPath);

        $this->assertNotEmpty($csrContent, 'CSR content should not be empty.');
        $this->assertNotEmpty($keyContent, 'Private key content should not be empty.');

        // Check that CSR content has the correct header.
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', $csrContent, 'CSR should have proper header.');

        // Check that private key content has a valid header (could be EC PRIVATE KEY or PRIVATE KEY).
        $this->assertMatchesRegularExpression('/BEGIN (EC )?PRIVATE KEY/', $keyContent, 'Private key should have a valid header.');

        // Clean up temporary files.
        unlink($csrPath);
        unlink($privateKeyPath);
    }

    /**
     * Test that missing a required parameter throws an exception.
     */
    public function test_missing_required_parameter(): void
    {
        $this->expectException(CertificateBuilderException::class);
        $this->expectExceptionMessage('Missing required parameter: businessCategory');

        // Create CertificateBuilder without setting businessCategory.
        $builder = new CertificateBuilder;
        $builder->setOrganizationIdentifier('312345678901233')
            ->setSerialNumber('Saleh', '1n', 'SME00023')
            ->setCommonName('My Organization')
            ->setOrganizationName('My Company')
            ->setOrganizationalUnitName('IT Department')
            ->setAddress('Riyadh 1234 Street')
            ->setInvoiceType(1100)
            ->setProduction(true);
        // Missing setBusinessCategory

        // Calling generate() should throw an exception.
        $builder->generate();
    }

    /**
     * Test that an Arabic legal name reaches the CSR subject intact instead of being reduced to blanks.
     */
    public function test_arabic_subject_names_are_kept(): void
    {
        $builder = $this->validBuilder()
            ->setOrganizationName('مؤسسة سارتوريز للخياطة الرجالية')
            ->setOrganizationalUnitName('الفرع الرئيسي');
        $builder->generate();

        $subject = openssl_csr_get_subject($builder->getCsr(), false);

        $this->assertSame('مؤسسة سارتوريز للخياطة الرجالية', $subject['organizationName']);
        $this->assertSame('الفرع الرئيسي', $subject['organizationalUnitName']);
    }

    /**
     * Test that subject names are capped at the X.509 limit of 64 characters, so OpenSSL does not reject them.
     */
    public function test_subject_names_are_capped_at_64_characters(): void
    {
        // 30 five-character words: the first 64 characters end inside a word, so nothing is trimmed after the cut.
        $builder = $this->validBuilder()->setOrganizationName(str_repeat('شركة ', 30));
        $builder->generate();

        $subject = openssl_csr_get_subject($builder->getCsr(), false);

        $this->assertSame(64, mb_strlen($subject['organizationName']));
    }

    /**
     * Test that whitespace in subject names is collapsed and trimmed.
     */
    public function test_subject_name_whitespace_is_collapsed(): void
    {
        $builder = $this->validBuilder()->setOrganizationName("  My \n\t  Company  ");
        $builder->generate();

        $subject = openssl_csr_get_subject($builder->getCsr(), false);

        $this->assertSame('My Company', $subject['organizationName']);
    }

    /**
     * Test that values written into the OpenSSL config stay ASCII and on one line.
     */
    public function test_config_values_stay_ascii_on_one_line(): void
    {
        $builder = $this->validBuilder()->setAddress("Riyadh طريق الملك فهد\nBldg 7421   12466");

        $address = (new \ReflectionProperty(CertificateBuilder::class, 'address'))->getValue($builder);
        $this->assertSame('Riyadh Bldg 7421 12466', $address);

        $builder->generate();
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', $builder->getCsr());
    }

    /**
     * Test that a name with nothing usable left is reported as missing rather than sent as blanks.
     */
    public function test_name_without_usable_characters_is_missing(): void
    {
        $this->expectException(CertificateBuilderException::class);
        $this->expectExceptionMessage('Missing required parameter: organizationName');

        $this->validBuilder()->setOrganizationName(' !!! ')->generate();
    }

    private function validBuilder(): CertificateBuilder
    {
        return (new CertificateBuilder)
            ->setOrganizationIdentifier('312345678901233')
            ->setSerialNumber('Saleh', '1n', 'SME00023')
            ->setCommonName('My Organization')
            ->setOrganizationName('My Company')
            ->setOrganizationalUnitName('IT Department')
            ->setAddress('Riyadh 1234 Street')
            ->setInvoiceType(100)
            ->setProduction(false)
            ->setBusinessCategory('Technology');
    }
}
