<?php

namespace GuzzleHttp;

/**
 * Trait implementing getPath on top of HasDataTrait
 */
trait GetPathTrait
{
    use HasDataTrait;

    /**
     * Gets a value from the collection using an array path.
     *
     * This method does not allow for keys that contain "/". You must traverse
     * the array manually or using something more advanced like JMESPath to
     * work with keys that contain "/".
     *
     *     // Get the bar key of a set of nested arrays.
     *     // This is equivalent to $collection['foo']['baz']['bar'] but won't
     *     // throw warnings for missing keys.
     *     $collection->getPath('foo/baz/bar');
     *
     * @param string $path Path to traverse and retrieve a value from
     *
     * @return mixed|null
     */
    public function getPath($path)
    {
        $data =& $this->data;
        $path = explode('/', $path);

        while (null !== ($part = array_shift($path))) {
            if (!is_array($data) || !isset($data[$part])) {
                return null;
            }
            $data =& $data[$part];
        }

        return $data;
    }
}
