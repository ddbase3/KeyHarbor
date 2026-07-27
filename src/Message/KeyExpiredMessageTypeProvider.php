<?php declare(strict_types=1);

namespace KeyHarbor\Message;

use MessagingFoundation\Api\IMessageTypeProvider;

final class KeyExpiredMessageTypeProvider implements IMessageTypeProvider {

	public static function getName(): string {
		return 'keyharborexpired';
	}

	public function getLabel(): string {
		return 'KeyHarbor credential expired';
	}

	public function getDescription(): string {
		return 'Notifies a credential owner when an API credential has expired.';
	}

	public function getDefaultSubject(): string {
		return 'API credential expired: {{key_label}}';
	}

	public function getDefaultBodyText(): string {
		return "Hello {{user_name}},\n\n" .
			"the API credential \"{{key_label}}\" expired on {{expires_at}}.\n\n" .
			"Credential: {{key_public_id}}\n" .
			"Services: {{service_labels}}\n" .
			"System: {{system_name}}\n\n" .
			"Manage credential: {{manage_url}}\n";
	}

	public function getDefaultBodyHtml(): string {
		return '';
	}

	public function getPlaceholders(): array {
		return [
			[
				'name' => 'user_name',
				'label' => 'User name',
				'description' => 'Display name of the credential owner.',
				'required' => true,
				'example' => 'Jane Doe'
			], [
				'name' => 'key_label',
				'label' => 'Credential label',
				'description' => 'User-managed label of the expired credential.',
				'required' => true,
				'example' => 'Reporting integration'
			], [
				'name' => 'key_public_id',
				'label' => 'Credential public id',
				'description' => 'Public lookup identifier of the credential.',
				'required' => true,
				'example' => '0123456789abcdef0123'
			], [
				'name' => 'expires_at',
				'label' => 'Expiration time',
				'description' => 'Formatted credential expiration time.',
				'required' => true,
				'example' => '2026-08-03 12:00:00 UTC'
			], [
				'name' => 'service_labels',
				'label' => 'Granted services',
				'description' => 'Comma-separated labels of granted services.',
				'required' => true,
				'example' => 'Report read, Report write'
			], [
				'name' => 'system_name',
				'label' => 'System name',
				'description' => 'Name of the current host and embedded system.',
				'required' => true,
				'example' => 'ILIAS / BASE3'
			], [
				'name' => 'manage_url',
				'label' => 'Management URL',
				'description' => 'Link to the KeyHarbor credential management display.',
				'required' => true,
				'example' => '/keymanagementdisplay.html'
			]
		];
	}

	public function getSchema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'user_name' => ['type' => 'string'],
				'key_label' => ['type' => 'string'],
				'key_public_id' => ['type' => 'string'],
				'expires_at' => ['type' => 'string'],
				'service_labels' => ['type' => 'string'],
				'system_name' => ['type' => 'string'],
				'manage_url' => ['type' => 'string']
			],
			'required' => [
				'user_name',
				'key_label',
				'key_public_id',
				'expires_at',
				'service_labels',
				'system_name',
				'manage_url'
			]
		];
	}
}
