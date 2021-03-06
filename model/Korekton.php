<?php
/**
 * This program is free software; you can redistribute it and/or
* modify it under the terms of the GNU General Public License
* as published by the Free Software Foundation; under version 2
* of the License (non-upgradable).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*
* Copyright (c) 2014 (original work) Open Assessment Technologies SA (under the project TAO-PRODUCT);
*
*/

namespace oat\kutimo\model;

use qtism\runtime\expressions\operators\OperatorProcessingException;
use qtism\runtime\expressions\operators\CustomOperatorProcessor;
use qtism\runtime\expressions\operators\OperandsCollection;
use qtism\data\expressions\Expression;
use qtism\common\datatypes\Float;
use qtism\common\datatypes\String;
use qtism\common\enums\BaseType;
use qtism\common\enums\Cardinality;
use common_ext_ExtensionsManager;
use common_ext_Extension;
use \DOMDocument;

/**
 * The Korekton operator is a QTI custom operator implementation which connects to a remote
 * RESTful web service to get the score of the item currently being in response processing phase.
 * 
 * The Korekton operator takes a single sub-expression which must have single cardinality and
 * a string or identifier baseType (empty strings/NULL accepted). This sub-expression represents
 * candidate response.
 *
 * It returns a value with single cardinality and base-type float. This value will be:
 *
 * * A float value of 0.0 if the sub-expression is NULL or an empty string
 * * A float value of 1.0 if the sub-expression is not NULL nor an empty string
 *
 * @author Jérôme Bogaerts <jerome@taotesting.com>
 *
 */
class Korekton extends CustomOperatorProcessor
{
    /**
     * A pointer on the Kutimo extension object.
     * 
     * @var common_ext_Extension
     */
    private $extension;
    
    /**
     * Create a new Korekton operator object.
     * 
     * @param \qtism\data\expressions\Expression $expression The Expression object describing the operations to be performed.
     * @param \qtism\runtime\expressions\operations\OperandsCollection $operands The operands given to the operator to be processed.
     */
    public function __construct(Expression $expression, OperandsCollection $operands)
    {
        parent::__construct($expression, $operands);
        $this->setExtension(common_ext_ExtensionsManager::singleton()->getExtensionById('kutimo'));
    }
    
    /**
     * Get a reference on the Kutimo extension object.
     * 
     * @return common_ext_Extension
     */
    protected function getExtension()
    {
        return $this->extension;
    }
    
    /**
     * Set a reference on the Kutimo extension object.
     * 
     * @param common_ext_Extension $extension
     */
    protected function setExtension(common_ext_Extension $extension)
    {
        $this->extension = $extension;
    }
    
    /**
     * Contains the business logic of the Korekton operator.
     *
     * @throws \qtism\runtime\expressions\operators\OperatorProcessingException OperatorProcessingException If more than one sub-expression is given, or the sub-expression has wrong cardinality and/or baseType. Finally, this error might be thrown if a network issue occurs.
     * @return \qtism\common\datatypes\Float A Float object.
     */
    public function process()
    {
        $operands = $this->getOperands();

        // The operator only accepts one operand.
        if (($c = count($operands)) > 1) {
            $msg = "The 'oat.kutimo.model.Korekton' custom operator takes only one sub-expression as a parameter, ${c} given.";
            throw new OperatorProcessingException($msg, $this, OperatorProcessingException::TOO_MUCH_OPERANDS);
        } elseif ($c === 0) {
            $msg = "The 'oat.kutimo.model.Korekton' custom operator takes one sub-expression as a parameter, none given.";
            throw new OperatorProcessingException($msg, $this, OperatorProcessingException::NOT_ENOUGH_OPERANDS);
        }

        $operand = $operands[0];
    
        // If operand is NULL, consider it as an empty string.
        if ($operand === null) {
            $operand = new String('');
        }
    
        // The operand must have a single cardinality and have a string baseType.
        if ($operand->getCardinality() !== Cardinality::SINGLE) {
            $msg = "The 'oat.kutimo.model.Korekton' custom operator only accept a first operand with single cardinality.";
            throw new OperatorProcessingException($msg, $this, OperatorProcessingException::WRONG_CARDINALITY);
        } elseif (($c = $operand->getBaseType()) !== BaseType::STRING && $c !== BaseType::IDENTIFIER) {
            $msg = "The 'oat.kutimo.model.Korekton' custom operator only accept a first operand with string or identifier baseType.";
            throw new OperatorProcessingException($msg, $this, OperatorProcessingException::WRONG_BASETYPE);
        }
        
        $url = $this->getEndPoint() . '/scoreItem';
        $curl = curl_init($url);
        $body  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $body .= '<scoreItemRequest xmlns="http://www.taotesting.com/xsd/korektonv1p0">' . "\n";
        $body .= '<itemID>' . $this->getState()->getAssessmentItem()->getIdentifier() . '</itemID>' . "\n";
        $body .= '<response>' . $operand->__toString() . '</response>' . "\n";
        $body .= '</scoreItemRequest>';
        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->getHttpTimeout());
        curl_setopt($curl, CURLOPT_USERPWD, $this->getUser() . ':' . $this->getPassword());
        
        $response = curl_exec($curl);
        curl_close($curl);

        return new Float($this->getScoreFromResponse($response));
    }
    
    /**
     * Get the Korekton end point to be consumed from the configuration.
     * 
     * @return string A Uniform Resource Locator (URL).
     */
    private function getEndPoint()
    {
        return $this->getExtension()->getConfig('korekton.endpoint');
    }
    
    /**
     * Get the HTTP time out time to be used from the configuration.
     * 
     * @return integer
     */
    private function getHttpTimeout()
    {
        return intval($this->getExtension()->getConfig('korekton.timeout'));
    }
    
    /**
     * Get the user name to be used to authenticate against the Korekton end point
     * from the configuration.
     * 
     * @return string
     */
    private function getUser()
    {
        return $this->getExtension()->getConfig('korekton.user');
    }
    
    /**
     * Get the password to be used to authenticate against the Korekton end point
     * from the configuration.
     * 
     * @return string
     */
    private function getPassword()
    {
        return $this->getExtension()->getConfig('korekton.password');
    }
    
    /**
     * Extract score from a string XML response payload.
     * 
     * @param string $response
     * @return float
     */
    private function getScoreFromResponse($response)
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadXML($response);
        return floatval($doc->getElementsByTagName('score')->item(0)->nodeValue);
    }
}