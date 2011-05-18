<?php

/**
 * general purpose wrapper class for image resizing utilities
 *
 * @author Marcus Kielly 
 */
class SWK_Utility_Image {

    private $_image = null;
    private $_source = null;
    public $mime;
    public $width;
    public $height;
    public $ratio;
    public $name;
    private $_r = 255;
    private $_g = 255;
    private $_b = 255;
    private $_types = array('image/jpeg', 'image/png', 'image/gif');
    private $_isFile = true;
    private $_isData = false;

    public function __construct() {
        
    }

    public function load($source, $data = false) {
        if ($data) {
            $this->_isData = true;
            $this->_isFile = false;
        }
        $this->_init($source);
    }

    /**
     * initialisation externalised from constructor to allow post construction reinitialising
     *
     * @param <type> $source
     */
    private function _init($source) {
        $initialised = false;
        switch (gettype($source)) {
            case 'string':
                if (is_file($source)) {
                    $initialised = $this->_initFromFile($source);
                    break;
                }
                if ($this->_isData) {
                    $resource = imagecreatefromstring($source);
                    $initialised = $this->_initFromResource($resource);
                    break;
                }
                throw new Exception('DV_Utility_Image::load could not load the source');
            case 'resource':
                $initialised = $this->_initFromResource($source);
                break;
            default:
                throw new Exception('DV_Utility_Image::load could not load the source');
        }

        if ($initialised) {
            $this->_calculate();
        }
    }

    /**
     * initialise from a resource
     *
     * @param <type> $resource
     */
    private function _initFromResource($resource) {
        $this->_original = $this->_image = $resource; //copy image resource in case of reload
        $this->_source = $resource;
        $this->_isFile = false;
        $this->name = 'resource';
        $this->mime = 'image/png';
        return true;
    }

    /**
     * initialise a traditional file resource
     *
     * @param <type> $source
     */
    private function _initFromFile($source) {
        if (!file_exists($source)) {
            throw new DV_Exception_File('DV_Utility_Image::load could not locate the source file:' . $source);
        }

        $info = getimagesize($source);
        $this->name = pathinfo($source, PATHINFO_FILENAME) . '.' . pathinfo($source, PATHINFO_EXTENSION);

        $this->mime = $info['mime'];
        if (!in_array($this->mime, $this->_types)) {
            throw new Exception('DV_Utility_Image does not accept files of MIME type ' . $info['mime']);
        }

        $this->_image = $this->_createImage($source);
        $this->_original = $this->_image; //copy image resource in case of reload
        $this->_source = $source;
        return true;
    }

    /**
     * creates an image resource from the path
     *
     * @param file path $source
     */
    private function _createImage($source) {
        switch ($this->mime) {
            case('image/jpeg'):
                $image = ImageCreateFromJPEG($source);
                break;

            case('image/gif'):
                $image = ImageCreateFromGIF($source);
                break;

            case('image/png'):
                $image = ImageCreateFromPNG($source);
                break;

            default:
                $image = ImageCreateFromJPEG($source);
                break;
        }
        return $image;
    }

    /**
     * external from constructor
     *
     * should be called prior to all operations in case of chaining
     * except where those operations are issue internally from a common method
     * i.e. resize
     */
    private function _calculate() {
        $this->width = ImageSX($this->_image);
        $this->height = ImageSY($this->_image);

        //if the image is landscape - the ratio is greater than 1, and vice versa
        $this->ratio = ($this->width / $this->height);
    }

    /**
     * resets the image class to use original image source
     */
    public function reload() {
        if ($this->_isFile) {
            if (!file_exists($this->_source)) {
                $this->_image = $this->_copy;
            } else {
                $this->_init($this->_source);
            }
        } else {
            $this->_init($this->_source);
        }
        $this->_calculate();
    }

    /**
     *
     * @param int $x target canvas width
     * @param int $y target canvas height
     * @return this
     */
    public function stretch($dst_w, $dst_h) {
        $this->_calculate();
        $canvas = $this->_makeCanvas($dst_w, $dst_h);
        ImageCopyResampled($canvas, $this->_image, 0, 0, 0, 0, $dst_w, $dst_h, $this->width, $this->height);
        $this->_image = $canvas;
        unset($canvas);
        return $this;
    }

    /**
     * scales an image to the target size - crops intelligently
     *
     *
     * will intelligently scale along the dominant axis to fit the required area
     * user can specify preferred alignment for crop
     * this factor comes more into play if x and y "vals" are specified
     *
     * @param int $x target canvas width
     * @param int $y target canvas height
     * @param string $x_align left | center | right
     * @param string $y_align top | center | bottom
     * @param int $x_val horizontal sample
     * @param int $y_val vertical sample
     */
    public function resize($dst_w, $dst_h, $options = array()) {
        $this->_calculate();
        $mode = isset($options['mode']) ? $options['mode'] : 'crop';
        $x_align = isset($options['x_align']) ? $options['x_align'] : 'center';
        $y_align = isset($options['y_align']) ? $options['y_align'] : 'top';

        // SAMPLE AREA
        $src_w = isset($options['src_w']) ? $options['src_w'] : $this->width;
        $src_h = isset($options['src_h']) ? $options['src_h'] : $this->height;
        // sanitise
        $src_w = (($src_w < 0) || ($src_w > $this->width)) ? $this->width : $src_w;
        $src_h = (($src_h < 0) || ($src_h > $this->height)) ? $this->height : $src_h;

        switch ($mode) {
            case 'fit':
                return $this->_scaleAndFit($dst_w, $dst_h, $x_align, $y_align);
                break;
            case 'crop':
                return $this->_scaleAndCrop($dst_w, $dst_h, $src_w, $src_h, $x_align, $y_align);
                break;
            case 'smart':
                return $this->_smartScale($dst_w, $dst_h, $src_w, $src_h);
                break;
            default:
                return $this->_scaleAndCrop($dst_w, $dst_h, $src_w, $src_h, $x_align, $y_align);
        }
        return;
    }

    /**
     * scales object and automatically calculates cropping
     *
     * image is cropped to favour dominant axis
     * i.e. if image is wider than it is tall, the height is cropped
     *
     * @param int $dst_w target image size
     * @param int $dst_h target image height
     * @param int $src_w sample width
     * @param int $src_h sample height
     * @param string $x_align left | center | right
     * @param string $y_align top | center | bottom
     * @return DV_Utility_Image
     */
    private function _scaleAndCrop($dst_w, $dst_h, $src_w, $src_h, $x_align, $y_align) {
        $src_x = $src_y = 0;
        $canvas = $this->_makeCanvas($dst_w, $dst_h);

        //CALCULATE RATIOS
        $x_ratio = $this->width / $dst_w;
        $y_ratio = $this->height / $dst_h;
        $dst_ratio = $dst_w / $dst_h;

        //PROCESS IMAGE ACCORDING TO RATIOS
        // WIDE RATIO
        if ($this->ratio < $dst_ratio) {
            $src_h = (int) floor($dst_h * $x_ratio);

            switch ($y_align) {
                case 'top':
                    $src_y = 0;
                    break;

                case 'center':
                    $src_y = (int) floor(($this->height - $src_h) / 2);
                    break;

                case 'bottom':
                    $src_y = ($this->height - $src_h);
                    break;

                default:
                    $src_y = 0;
            }
        }

        // TALL RATIO
        if ($this->ratio > $dst_ratio) {
            $src_w = (int) floor($dst_w * $y_ratio);
            switch ($x_align) {
                case 'left':
                    $src_x = 0;
                    break;

                case 'center':
                    $src_x = (int) floor(($this->width - $src_w) / 2);
                    break;

                case 'right':
                    $src_x = ($this->width > $dst_w) ? $this->width - $dst_w : $dst_w - $this->width;
                    $src_x = $src_x / $y_ratio;
                    break;
            }
        }

        // EQUAL RATIO
        if ($this->ratio == $dst_ratio) {
            $src_w = $this->width;
            $src_h = $this->height;
        }

        ImageCopyResampled($canvas, $this->_image, 0, 0, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h);
        $this->_image = $canvas;
        unset($canvas);
        return $this;
    }

    /**
     * scales image to best fit preferred image size
     *
     * will scale to axes that best matches proportions
     *
     * @param int $dst_w desired width
     * @param int $dst_h desired height
     * @param string $x_align left | center | right
     * @param string $y_align top | center | bottom
     * @return DV_Utility_Image
     */
    private function _smartScale($dst_w, $dst_h) {

        $x_ratio = $this->width / $dst_w;
        $y_ratio = $this->height / $dst_h;
        $dst_ratio = $dst_w / $dst_h;
        $dst_x = $dst_y = 0;

        //TALL RATIO
        if ($this->ratio < $dst_ratio) {
            $dst_h = round($this->height / $y_ratio);
            $dst_w = round($this->width / $y_ratio);
        }

        //WIDE RATIO
        if ($this->ratio > $dst_ratio) {
            $dst_h = round($this->height / $x_ratio);
            $dst_w = round($this->width / $x_ratio);
        }

        //EQUAL RATIO
        if ($this->ratio == $dst_ratio) {
            $dst_h = round($this->width / $x_ratio);
            $dst_w = round($this->height / $y_ratio);
        }

        $canvas = $this->_makeCanvas($dst_w, $dst_h);

        ImageCopyResampled($canvas, $this->_image, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $this->width, $this->height);
        $this->_image = $canvas;

        unset($canvas);
        return $this;
    }

    /**
     * scales image to best fit target area
     *
     * @param int $dst_w desired width
     * @param int $dst_h desired height
     * @param string $x_align left | center | right
     * @param string $y_align top | center | bottom
     * @return DV_Utility_Image
     */
    private function _scaleAndFit($dst_w, $dst_h, $x_align, $y_align) {
        $canvas = $this->_makeCanvas($dst_w, $dst_h);

        $x_ratio = $this->width / $dst_w;
        $y_ratio = $this->height / $dst_h;
        $dst_ratio = $dst_w / $dst_h;
        $dst_x = $dst_y = 0;

        //TALL RATIO
        if ($this->ratio < $dst_ratio) {
            $o_w = $dst_w;
            $o_h = $dst_h;
            $dst_h = round($this->height / $y_ratio);
            $dst_w = round($this->width / $y_ratio);
            $dst_y = 0;
            switch ($x_align) {
                case 'left':
                    $dst_x = 0;
                    break;
                case 'center':
                    $dst_x = ($o_w - $dst_w) / 2;
                    break;
                case 'right':
                    $dst_x = $o_w - $dst_w;
                    break;
                default:
            }
        }

        //WIDE RATIO
        if ($this->ratio > $dst_ratio) {
            $o_w = $dst_w;
            $o_h = $dst_h;
            $dst_h = round($this->height / $x_ratio);
            $dst_w = round($this->width / $x_ratio);
            $dst_x = 0;
            switch ($y_align) {
                case 'top':
                    $dst_y = 0;
                    break;
                case 'center':
                    $dst_y = ($o_h - $dst_h) / 2;
                    break;
                case 'bottom':
                    $dst_y = $o_h - $dst_h;
                    break;
                default:
            }
        }


        //EQUAL RATIO
        if ($this->ratio == $dst_ratio) {
            $dst_x = round($this->width / $x_ratio);
            $dst_y = round($this->height / $y_ratio);
        }
        ImageCopyResampled($canvas, $this->_image, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $this->width, $this->height);
        $this->_image = $canvas;
        unset($canvas);
        return $this;
    }

    /**
     * flips image by negative scaling along required axis
     *
     * @param string $axis axis x | y | xy
     * @return DV_Utility_Image
     */
    public function flip($axis = 'x') {
        $this->_calculate();
        $src_y = $src_x = 0;
        $canvas = $this->_makeCanvas($this->width, $this->height);

        switch ($axis) {
            case 'y':
                $src_y = $this->height - 1;
                $src_w = $this->width;
                $src_h = -$this->height;
                break;

            case 'x':
                $src_x = $this->width - 1;
                $src_w = -$this->width;
                $src_h = $this->height;
                break;

            case 'xy':
                $src_x = $this->width - 1;
                $src_y = $this->height - 1;
                $src_w = -$this->width;
                $src_h = -$this->height;
                break;

            default:
                $src_x = $this->width - 1;
                $src_w = -$this->width;
                $src_h = $this->height;
        }
        ImageCopyResampled($canvas, $this->_image, 0, 0, $src_x, $src_y, $this->width, $this->height, $src_w, $src_h);
        $this->_image = $canvas;
        unset($canvas);
        return $this;
    }

    /**
     *
     * @param int $angle angle
     * @param string $direction cw | ccw
     * @return DV_Utility_Image
     */
    public function rotate($angle, $direction = 'cw') {
        $this->_calculate();
        if ($direction == 'cw') {
            $angle = 360 - $angle;
        }
        $canvas = $this->_makeCanvas($this->width, $this->height);
        $canvas = imagerotate($this->_image, $angle, 0);
        $this->_image = $canvas;
        unset($canvas);
        return $this;
    }

    /**
     * extracts a sample of the original image
     *
     * @param int $src_x x origin
     * @param int $src_y y origin
     * @param int $src_w sample width
     * @param int $src_h sample height
     * @return DV_Utility_Image
     */
    public function sample($src_x, $src_y, $src_w, $src_h) {
        $this->_calculate();
        //sanitise
        //forbid out of range positions
        $src_x = ( $src_x < 0 || $src_x > $this->width ) ? 0 : $src_x;
        $src_y = ( $src_y < 0 || $src_y > $this->height ) ? 0 : $src_y;
        $src_x = (($src_x + $src_w) > $this->width) ? $src_x = $this->width - $src_w : $src_w;
        $src_y = (($src_y + $src_h) < $this->height) ? $src_y = $this->height - $src_y : $src_y;

        //forbid out of range sample ranges
        $src_w = ($src_w < 0 || $src_w > $this->width) ? $this->width : $src_w;
        $src_h = ($src_h < 0 || $src_h > $this->height) ? $this->height : $src_h;

        $canvas_w = $src_w;
        $canvas_h = $src_h;
        $canvas = $this->_makeCanvas($canvas_w, $canvas_h);

        ImageCopyResampled($canvas, $this->_image, 0, 0, $src_x, $src_y, $canvas_w, $canvas_h, $src_w, $src_h);
        $this->_image = $canvas;
        unset($canvas);
        return $this;
    }

    /**
     * saves the image
     * specify format separately if required
     *
     * @param string $path
     * @param string $name
     * @param string $format default 'jpg'
     */
    public function save($path, $name, $format = 'jpg') {
        $this->_verifyPath($path);

        //add directory separator if not present
        if (substr($path, -1) != '/') {
            $path .= '/';
        }

        //remove any format names from the name string
        $name = str_replace(array('.jpg', '.gif', '.png'), '', $name);

        //process the format
        if (!in_array($format, $this->_types)) {
            $format = 'jpg';
        }

        switch ($format) {
            case 'jpg':
                ImageJPEG($this->_image, $path . $name . '.' . $format);
                break;
            case 'gif':

                ImageGIF($this->_image, $path . $name . '.' . $format);
                break;
            case 'png':

                ImagePNG($this->_image, $path . $name . '.' . $format);
                break;
            default:
                ImageJPEG($this->_image, $path . $name . '.' . $format);
        }
    }

    /**
     * destroy the original file if appropriate
     */
    public function destroy() {
        if ($this->_isFile) {
            unlink($this->_source);
        }
    }

    /**
     * sets headers and outputs image stream data
     */
    public function show() {
        $data = $this->_make();
        header('Content-length', strlen($data));
        header('Content-type: image/jpg');
        echo $data;
    }

    private function _make() {
        ob_start();
        ImageJPEG($this->_image, null, 100);
        $data = ob_get_clean();
        return $data;
    }

    public function getString() {
        return $this->_make();
    }

    /**
     * set background color
     *
     * set this prior to image manipulation if required
     *
     * @param int $r
     * @param int $g
     * @param int $b
     * @return DV_Utility_Image
     */
    public function rgb($r = 255, $g = 255, $b = 255) {
        $this->_r = $r;
        $this->_g = $g;
        $this->_b = $b;
        return $this;
    }

    /**
     * creates a canvas for the target
     *
     * @param int $x width
     * @param int $y height
     * @return Image
     */
    private function _makeCanvas($x, $y) {
        $canvas = ImageCreateTrueColor($x, $y);
        $color = imagecolorallocate($canvas, $this->_r, $this->_g, $this->_b);
        imagefill($canvas, 0, 0, $color);
        return $canvas;
    }

    /**
     * confirm path integrity prior to save
     * 
     * @param string $path
     */
    private function _verifyPath($path) {
        if (!is_dir($path)) {
            throw new Exception('save path is not a valid directory');
        }
        if (!is_writable($path)) {
            throw new Exception('save path is not writable');
        }
    }

}

