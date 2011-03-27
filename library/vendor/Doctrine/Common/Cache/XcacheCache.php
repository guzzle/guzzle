<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\Common\Cache;

/**
 * Xcache cache driver.
 *
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link    www.doctrine-project.org
 * @since   2.0
 * @version $Revision: 3938 $
 * @author  Benjamin Eberlei <kontakt@beberlei.de>
 * @author  Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author  Jonathan Wage <jonwage@gmail.com>
 * @author  Roman Borschel <roman@code-factory.org>
 * @author  David Abdemoulaie <dave@hobodave.com>
 */
class XcacheCache extends AbstractCache
{
    /**
     * {@inheritdoc}
     */
    public function getIds()
    {
        $this->_checkAuth();
        $keys = array();

        for ($i = 0, $count = xcache_count(XC_TYPE_VAR); $i < $count; $i++) {
            $entries = xcache_list(XC_TYPE_VAR, $i);

            if (is_array($entries['cache_list'])) {
                foreach ($entries['cache_list'] as $entry) {
                    $keys[] = $entry['name'];
                }
            }
        }

        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    protected function _doFetch($id)
    {
        return $this->_doContains($id) ? unserialize(xcache_get($id)) : false;
    }

    /**
     * {@inheritdoc}
     */
    protected function _doContains($id)
    {
        return xcache_isset($id);
    }

    /**
     * {@inheritdoc}
     */
    protected function _doSave($id, $data, $lifeTime = 0)
    {
        return xcache_set($id, serialize($data), (int) $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    protected function _doDelete($id)
    {
        return xcache_unset($id);
    }


    /**
     * Checks that xcache.admin.enable_auth is Off
     *
     * @throws \BadMethodCallException When xcache.admin.enable_auth is On
     * @return void
     */
    protected function _checkAuth()
    {
        if (ini_get('xcache.admin.enable_auth')) {
            throw new \BadMethodCallException('To use all features of \Doctrine\Common\Cache\XcacheCache, you must set "xcache.admin.enable_auth" to "Off" in your php.ini.');
        }
    }
}