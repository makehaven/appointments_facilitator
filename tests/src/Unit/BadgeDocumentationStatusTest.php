<?php

namespace Drupal\Tests\appointment_facilitator\Unit;

use Drupal\appointment_facilitator\Service\BadgePrerequisiteGate;
use Drupal\appointment_facilitator\Service\BadgeUserStatusResolver;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Maps gate output to the documentation pill shown on the badge page.
 *
 * The important case: a member registered for a class that issues the badge
 * must never be told to submit the training-documentation form, even when
 * they already submitted one that is still "under review".
 *
 * @group appointment_facilitator
 */
class BadgeDocumentationStatusTest extends UnitTestCase {

  private function resolver(): BadgeUserStatusResolver {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $gate = $this->createMock(BadgePrerequisiteGate::class);
    return new class($etm, $gate) extends BadgeUserStatusResolver {

      public function fromGate(array $gate): string {
        return $this->documentationStatusFromGate($gate);
      }

    };
  }

  /**
   * @dataProvider gateProvider
   */
  public function testDocumentationStatusFromGate(array $gate, string $expected): void {
    $this->assertSame($expected, $this->resolver()->fromGate($gate));
  }

  public static function gateProvider(): array {
    return [
      'no docs required' => [
        ['requires_documentation' => FALSE],
        BadgeUserStatusResolver::DOCS_NOT_REQUIRED,
      ],
      'approved wins over everything' => [
        ['requires_documentation' => TRUE, 'documentation_approved' => TRUE, 'class_registration_satisfies_docs' => TRUE],
        BadgeUserStatusResolver::DOCS_APPROVED,
      ],
      'class registration, nothing submitted' => [
        ['requires_documentation' => TRUE, 'documentation_approved' => FALSE, 'class_registration_satisfies_docs' => TRUE],
        BadgeUserStatusResolver::DOCS_SATISFIED_BY_CLASS,
      ],
      'class registration outranks a form still under review' => [
        ['requires_documentation' => TRUE, 'documentation_approved' => FALSE, 'documentation_submitted' => TRUE, 'class_registration_satisfies_docs' => TRUE],
        BadgeUserStatusResolver::DOCS_SATISFIED_BY_CLASS,
      ],
      'submitted, no class' => [
        ['requires_documentation' => TRUE, 'documentation_approved' => FALSE, 'documentation_submitted' => TRUE],
        BadgeUserStatusResolver::DOCS_PENDING_REVIEW,
      ],
      'nothing at all' => [
        ['requires_documentation' => TRUE, 'documentation_approved' => FALSE],
        BadgeUserStatusResolver::DOCS_NOT_SUBMITTED,
      ],
    ];
  }

}
