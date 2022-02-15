<?php
namespace Sandstorm\TemplateMailer\Domain\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\FluidAdaptor\View\StandaloneView;
use Neos\SwiftMailer\Message;
use Pelago\Emogrifier\CssInliner;
use Psr\Log\LoggerInterface;
use Sandstorm\TemplateMailer\Exception;

/**
 * @Flow\Scope("singleton")
 */
class EmailService
{
    const LOG_LEVEL_NONE = 'none';
    const LOG_LEVEL_LOG = 'log';
    const LOG_LEVEL_THROW = 'throw';

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var ThrowableStorageInterface
     */
    protected $throwableStorage;

    /**
     * @Flow\Inject
     * @var ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var array
     * @Flow\InjectConfiguration(path="senderAddresses")
     */
    protected $senderAddresses;

    /**
     * @var array
     * @Flow\InjectConfiguration(path="replyToAddresses")
     */
    protected $replyToAddresses;

    /**
     * @var array
     * @Flow\InjectConfiguration(path="templatePackages")
     */
    protected $templatePackages;

    /**
     * @var array
     * @Flow\InjectConfiguration(path="defaultTemplateVariables")
     */
    protected $defaultTemplateVariables;

    /**
     * @var string
     * @Flow\InjectConfiguration(path="logging.sendingErrors")
     */
    protected $logSendingErrors;

    /**
     * @var string
     * @Flow\InjectConfiguration(path="logging.sendingSuccess")
     */
    protected $logSendingSuccess;

    /**
     * @param string $templateName name of the email template to use @see renderEmailBody()
     * @param string $subject subject of the email
     * @param array $recipient recipient of the email in the format ['<emailAddress>', ...]
     * @param array $variables variables that will be available in the email template. in the format ['<key>' => '<value>', ...]
     * @param string|array $sender Either an array with the format ['<emailAddress>' => 'Sender Name'], or a string which identifies a configured sender address
     * @param array $cc ccs of the email in the format ['<emailAddress>', ...]
     * @param array $bcc bccs of the email in the format ['<emailAddress>', ...]
     * @param array $attachments attachment array in the format [['data' => '<attachmentbinary>' ,'filename' => '<filename>', 'contentType' => '<mimeType, e.g. application/pdf>'], ...]
     * @param string|array|null $replyTo Either an array with the format ['<emailAddress>' => 'Reply To Name'], or a string which identifies a configured reply to address
     * @param array $additionalHeaders additional headers in the format ['<key>' => '<value>', ...]
     * @return boolean TRUE on success, otherwise FALSE
     * @throws Exception
     * @throws \Neos\FluidAdaptor\Exception
     */
    public function sendTemplateEmail(
        string $templateName,
        string $subject,
        array $recipient,
        array $variables = [],
        $sender = 'default',
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
        $replyTo = null,
        array $additionalHeaders = []
    ): bool
    {
        $targetPackage = $this->findFirstPackageContainingEmailTemplate($templateName);
        $variables = $this->addDefaultTemplateVariables($variables);
        $plaintextBody = $this->renderEmailBody($templateName, $targetPackage, 'txt', $variables);
        $htmlBody = $this->renderEmailBody($templateName, $targetPackage, 'html', $variables);

        $senderAddress = $this->resolveSenderAddress($sender);

        $mail = new Message();
        $mail->setFrom($senderAddress)
            ->setTo($recipient)
            ->setCc($cc)
            ->setBcc($bcc)
            ->setSubject($subject)
            ->setBody($plaintextBody)
            ->addPart($htmlBody, 'text/html');

        $replyToAddresses = $this->resolveReplyToAddress($replyTo);
        if (count($replyToAddresses) > 0) {
            $mail->setReplyTo($replyToAddresses);
        }

        if (count($additionalHeaders) > 0) {
            foreach ($additionalHeaders as $additionalHeaderName => $additionalHeaderValue) {
                $mail->getHeaders()->addTextHeader($additionalHeaderName, $additionalHeaderValue);
            }
        }

        if (count($attachments) > 0) {
            foreach ($attachments as $attachment) {
                $mail->attach(new \Swift_Attachment($attachment['data'], $attachment['filename'], $attachment['contentType']));
            }
        }

        return $this->sendMail($mail);
    }

    /**
     * Renders the email body of a template. Can be used directly to only render the email html body.
     * if the given format is htm or html, will automatically emogrify the email body.
     * This can be skipped by setting the last parameter to false.
     *
     * @param string $templateName
     * @param string $templateName
     * @param string $format
     * @param array $variables
     * @param bool $emogrify
     * @return string
     * @throws \Neos\FluidAdaptor\Exception
     */
    public function renderEmailBody(string $templateName, string $templatePackage, string $format, array $variables, bool $emogrify = true): string
    {
        $standaloneView = new StandaloneView();
        $request = $standaloneView->getRequest();
        //        $request->setControllerPackageKey('Sandstorm.Maklerapp'); TODO needed?
        $request->setFormat($format);

        $templatePath = sprintf('resource://%s/Private/EmailTemplates/', $templatePackage);
        $templatePathAndFilename = sprintf('%s%s.%s', $templatePath, $templateName, $format);
        $standaloneView->setTemplatePathAndFilename($templatePathAndFilename);
        $standaloneView->setLayoutRootPath($templatePath . 'Layouts');
        $standaloneView->setPartialRootPath($templatePath . 'Partials');
        $standaloneView->assignMultiple($variables);
        $emailBody = $standaloneView->render();

        // Emogrify - this will inline any styles in the HTML for proper display in Gmail.
        $emogrifiedFormats = ['htm', 'html'];
        if ($emogrify && in_array($format, $emogrifiedFormats)) {
            $emailBody = CssInliner::fromHtml($emailBody)->inlineCss()->render();
        }

        return $emailBody;
    }

    /**
     * @param array $passedVariables the template variables passed by the call to sendTemplateEmail().
     * @return array The passed array, merged with the default variables.
     * @throws \Neos\Flow\Configuration\Exception\InvalidConfigurationTypeException
     */
    protected function addDefaultTemplateVariables(array $passedVariables): array
    {
        $defaultVariables = [];
        foreach ($this->defaultTemplateVariables as $variableName => $configurationPath) {
            $defaultVariables[$variableName] = $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $configurationPath);
        }
        return $passedVariables + $defaultVariables;
    }

    /**
     * @param array|string $sender if it's an array, returns it directly. If it's a string, tries to find it in the config and resolve it.
     * @return array
     * @throws Exception
     */
    protected function resolveSenderAddress($sender): array
    {
        return $this->resolveAddress($sender, $this->senderAddresses, 'senderAddresses');
    }

    /**
     * @param array|string $replyTo if it's an array, returns it directly. If it's a string, tries to find it in the config and resolve it.
     * @return array
     * @throws Exception
     */
    protected function resolveReplyToAddress($replyTo): array
    {
        if ($replyTo !== null) {
            return $this->resolveAddress($replyTo, $this->replyToAddresses, 'replyToAddresses');
        } else {
            return [];
        }
    }

    /**
     * @param array|string $addressKeyOrAddresses if it's an array, returns it directly. If it's a string, tries to find it in the config and resolve it.
     * @param array $addressesConfig the configured addresses
     * @param string $description a description for exception messages
     * @return array
     * @throws Exception
     */
    protected function resolveAddress($addressKeyOrAddresses, $addressesConfig, $description): array
    {
        if (is_array($addressKeyOrAddresses)) {
            return $addressKeyOrAddresses;
        }
        if (!isset($addressesConfig[$addressKeyOrAddresses])) {
            throw new Exception(sprintf('The given address string was not found in config. Please check config path "Sandstorm.TemplateMailer.%s.%s".', $description, $addressKeyOrAddresses),
                1489787274);
        }
        if (!isset($addressesConfig[$addressKeyOrAddresses]['name']) || !isset($addressesConfig[$addressKeyOrAddresses]['address'])) {
            throw new Exception(sprintf('The given sender is not correctly configured - "name" or "address" are missing. Please check config path "Sandstorm.TemplateMailer.%s.%s".',
                $description, $addressKeyOrAddresses), 1489787274);
        }
        return [$addressesConfig[$addressKeyOrAddresses]['address'] => $addressesConfig[$addressKeyOrAddresses]['name']];
    }

    /**
     * @param string $templateName
     * @return string
     * @throws Exception
     */
    protected function findFirstPackageContainingEmailTemplate(string $templateName): string
    {
        if (count($this->templatePackages) === 0) {
            throw new Exception('No packages to load email templates from were registered. Please set the config path "Sandstorm.TemplateMailer.templatePackages" correctly!',
                1489787278);
        }

        ksort($this->templatePackages);
        foreach ($this->templatePackages as $package) {
            $templatePath = sprintf('resource://%s/Private/EmailTemplates/%s.html', $package, $templateName);
            if (file_exists($templatePath)) {
                return $package;
            }
        }
        throw new Exception(sprintf('No package containing an email template named "%s" was found. Checked packages: "%s"', $templateName, implode(', ', $this->templatePackages)),
            1489787275);
    }

    /**
     * Sends a mail and logs or throws any errors, depending on configuration
     *
     * @param Message $mail
     * @return boolean TRUE on success, otherwise FALSE
     * @throws \Exception
     */
    protected function sendMail(Message $mail): bool
    {
        $allRecipients = $mail->getTo() + $mail->getCc() + $mail->getBcc();
        $totalNumberOfRecipients = count($allRecipients);
        $actualNumberOfRecipients = 0;
        $exceptionMessage = '';

        try {
            $actualNumberOfRecipients = $mail->send();
        } catch (\Exception $e) {
            $exceptionMessage = $e->getMessage();
            if ($this->logSendingErrors === self::LOG_LEVEL_LOG) {
                $this->throwableStorage->logThrowable($e);
            } elseif ($this->logSendingErrors === self::LOG_LEVEL_THROW) {
                throw $e;
            }
        }

        $emailInfo = [
            'recipients' => array_keys($mail->getTo() + $mail->getCc() + $mail->getBcc()),
            'failedRecipients' => $mail->getFailedRecipients(),
            'subject' => $mail->getSubject(),
            'id' => (string)$mail->getHeaders()->get('Message-ID')
        ];
        if (strlen($exceptionMessage) > 0) {
            $emailInfo['exception'] = $exceptionMessage;
        }

        if ($actualNumberOfRecipients < $totalNumberOfRecipients && $this->logSendingErrors === self::LOG_LEVEL_LOG) {
            $this->systemLogger->error(
                sprintf('Could not send an email to all given recipients. Given %s, sent to %s', $totalNumberOfRecipients, $actualNumberOfRecipients),
                array_merge($emailInfo, LogEnvironment::fromMethodName(__METHOD__)));
            return false;
        }

        if ($this->logSendingSuccess === self::LOG_LEVEL_LOG) {
            $this->systemLogger->info('Email sent successfully.', array_merge($emailInfo, LogEnvironment::fromMethodName(__METHOD__)));
        }
        return true;
    }
}
