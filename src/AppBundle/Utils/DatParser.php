<?php

namespace AppBundle\Utils;

use AppBundle\Exception\FileNotFoundException;
use AppBundle\Exception\ParseErrorException;
use League\Flysystem\File;
use Symfony\Component\Asset\Exception\LogicException;

class DatParser
{
    /**
     * Parse LDraw .dat file header identifying model store data to array.
     *
     * [
     *  'id' => string
     *  'name' => string
     *  'category' => string
     *  'keywords' => []
     *  'author' => string
     *  'modified' => DateTime
     *  'type' => string
     *  'subparts' => []
     * ]
     *
     * LDraw.org Standards: Official Library Header Specification (http://www.ldraw.org/article/398.html)
     *
     * @return array
     * @throws FileNotFoundException|ParseErrorException
     */
    public function parse($file)
    {
        if(file_exists($file)) {
            $model = [
                'id' => null,
                'name' => null,
                'category' => null,
                'keywords' => [],
                'author' => null,
                'modified' => null,
                'type' => null,
                'subparts' => [],
                'parent' => null
            ];

            try {
                $handle = fopen($file, 'r');

                if ($handle) {
                    $firstLine = false;

                    while (($line = fgets($handle)) !== false) {
                        $line = trim($line);

                        // Comments or META Commands
                        if (strpos($line, '0 ') === 0) {
                            $line = preg_replace('/^0 /', '', $line);

                            // 0 <CategoryName> <PartDescription>
                            if (!$firstLine) {
                                $array = explode(' ', ltrim(trim($line, 2), '=_~'));
                                $model['category'] = isset($array[0]) ? $array[0] : '';
                                $model['name'] = preg_replace('/ {2,}/', ' ', ltrim($line, '=_'));

                                $firstLine = true;
                            } // 0 !CATEGORY <CategoryName>
                            elseif (strpos($line, '!CATEGORY ') === 0) {
                                $model['category'] = trim(preg_replace('/^!CATEGORY /', '', $line));
                            } // 0 !KEYWORDS <first keyword>, <second keyword>, ..., <last keyword>
                            elseif (strpos($line, '!KEYWORDS ') === 0) {
                                $model['keywords'] = explode(', ', preg_replace('/^!KEYWORDS /', '', $line));
                            } // 0 Name: <Filename>.dat
                            elseif (strpos($line, 'Name: ') === 0 && !isset($header['id'])) {
                                $model['id'] = preg_replace('/(^Name: )(.*)(.dat)/', '$2', $line);
                            } // 0 Author: <Realname> [<Username>]
                            elseif (strpos($line, 'Author: ') === 0) {
                                $model['author'] = preg_replace('/^Author: /', '', $line);
                            } // 0 !LDRAW_ORG Part|Subpart|Primitive|48_Primitive|Shortcut (optional qualifier(s)) ORIGINAL|UPDATE YYYY-RR
                            elseif (strpos($line, '!LDRAW_ORG ') === 0) {
                                $type = preg_replace('/(^!LDRAW_ORG )(.*)( UPDATE| ORIGINAL)(.*)/', '$2', $line);

                                $model['type'] = $type;

                                // Last modification date in format YYYY-RR
                                $date = preg_replace('/(^!LDRAW_ORG )(.*)( UPDATE | ORIGINAL )(.*)/', '$4', $line);
                                if (preg_match('/^[1-2][0-9]{3}-[0-9]{2}$/', $date)) {
                                    $model['modified'] = \DateTime::createFromFormat('Y-m-d H:i:s', $date . '-01 00:00:00');
                                }
                            }
                        } elseif (strpos($line, '1 ') === 0) {
                            $id = $this->getReferencedModelNumber($line);

                            if(isset($model['subparts'][$id])) {
                                $model['subparts'][$id] = $model['subparts'][$id] + 1;
                            } else {
                                $model['subparts'][$id] = 1;
                            }
                        }
                    }

                    if ($this->isSticker($model['name'], $model['id'])) {
                        $model['type'] = 'Sticker';
                    } elseif (count($model['subparts']) == 1 && in_array($model['type'], ['Part Alias', 'Shortcut Physical_Colour', 'Shortcut Alias', 'Part Physical_Colour'])) {
                        $model['parent'] = array_keys($model['subparts'])[0];
                    } elseif ($parent = $this->getPrintedModelParentNumber($model['id'])) {
                        $model['type'] = 'Printed';
                        $model['parent'] = $parent;
                    } elseif ($parent = $this->getObsoleteModelParentNumber($model['name'])) {
                        $model['type'] = 'Alias';
                        $model['parent'] = $parent;
                    } elseif (strpos($model['name'], '~') === 0 && $model['type'] != 'Alias') {
                        $model['type'] = 'Obsolete/Subpart';
                    }

                    fclose($handle);

                    return $model;
                }
            } catch (\Exception $exception) {
                throw new ParseErrorException($file);
            }
        }
        throw new FileNotFoundException($file);
    }

    /**
     * Get file reference from part line.
     *
     * Line type 1 is a sub-file reference. The generic format is:
     *  1 <colour> x y z a b c d e f g h i <file>
     *
     * LDraw.org Standards: File Format 1.0.2 (http://www.ldraw.org/article/218.html)
     *
     * @param $line
     *
     * @return string|null Filename of referenced part
     */
    public function getReferencedModelNumber($line)
    {
        if(preg_match('/^1 16 0 0 0 -1 0 0 0 1 0 0 0 1 (.*)\.(dat|DAT)$/', $line, $matches))
            return null;
        if (preg_match('/^1(.*) (.*)\.(dat|DAT)$/', $line, $matches)) {
            return $matches[2];
        }

        return null;
    }

    /**
     * Get printed part parent id.
     *
     *  part name in format:
     *  nnnPxx, nnnnPxx, nnnnnPxx, nnnaPxx, nnnnaPxx (a = alpha, n= numeric, x = alphanumeric)
     *
     *  http://www.ldraw.org/library/tracker/ref/numberfaq/
     *
     * @param $id
     *
     * @return string|null LDraw number of printed part parent
     */
    public function getPrintedModelParentNumber($id)
    {
        if (preg_match('/(^.*)(p[0-9a-z][0-9a-z][0-9a-z]{0,1})$/', $id, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if part is shortcut part of stricker and part.
     *
     * part name in format:
     *  nnnDnn, nnnnDnn, nnnnnDnn (a = alpha, n= numeric, x = alphanumeric)
     *
     *  http://www.ldraw.org/library/tracker/ref/numberfaq/
     *
     * @param $name
     * @param $number
     *
     * @return string|null LDraw number of printed part parent
     */
    public function isSticker($name, $number)
    {
        if (strpos($name, 'Sticker') === 0) {
            return true;
        }

        // Check if in format nnnDaa == sticker
        return preg_match('/(^.*)(d[a-z0-9][a-z0-9])$/', $number);
    }

    /**
     * Get parent of obsolete part kept for reference.
     *
     *  part description in format:
     *  ~Moved to {new_number}
     *
     * http://www.ldraw.org/article/398.html  (Appendix II (02-Oct-06))
     *
     * @param $name
     *
     * @return string|null Filename of referenced part
     */
    public function getObsoleteModelParentNumber($name)
    {
        if (preg_match('/^(~Moved to )(.*)$/', $name, $matches)) {
            return $matches[2];
        }

        return null;
    }
}
