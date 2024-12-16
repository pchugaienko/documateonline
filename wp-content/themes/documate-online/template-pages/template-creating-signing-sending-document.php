<?php

/**
 *  Template Name: Tabs Form
 *  Template Post Type: page
 */

get_header();
?>
    <main id="primary" class="site-main">
        <div class="wrapper">
            <div class="tabs">
                <span class="tab">Створити документ</span>
                <span class="tab">Підписати документ</span>
                <span class="tab">Відправити документ</span>
            </div>
            <div class="tab_content">
                <div class="tab_item">
                    <?php
                        the_content();
                    ?>
                </div>
                <div class="tab_item">
                    <!--
            Батківський елемент для відображення iframe,
            який завантажує сторінку SignWidget
        -->
                    <div id="sign-widget-parent" style="width:700px;height:1000px">
                    </div>

                    <!--
                        Батківський елемент для відображення QR-коду
                    -->
                    <div id="qr-code-block" style="display:none">

                    </div>

                    <!--
                        Батківський елемент для відображення меню з підпису даних
                    -->
                    <script>
                        //=============================================================================

                        /*
                            Ідентифікатор батківського елементу для відображення iframe,
                            який завантажує сторінку SignWidget
                        */
                        const SIGN_WIDGET_PARENT_ID		= "sign-widget-parent";
                        /*
                            Ідентифікатор iframe, який завантажує сторінку SignWidget
                        */
                        const SIGN_WIDGET_ID			= "sign-widget";
                        /*
                            URI з адресою за якою розташована сторінка SignWidget
                        */
                        const SIGN_WIDGET_URI			= "https://test.id.gov.ua/sign-widget/v2022testnew/";

                        //=============================================================================

                        /*
                            Створення об'єкту типу EndUser для взаємодії з iframe,
                            який завантажує сторінку SignWidget
                        */
                        var euSign = new EndUser(
                            SIGN_WIDGET_PARENT_ID,
                            SIGN_WIDGET_ID,
                            SIGN_WIDGET_URI,
                            EndUser.FormType.SignFile
                        );

                        //=============================================================================

                        /*
                            Обробник сповіщень на підтвердження операції з використання ос. ключа
                            за допомогою сканування QR-коду в мобільному додатку сервісу підпису
                        */
                        function onConfirmKSPOperation(event) {
                            var node = '';
                            console.log(event.url);
                            node += '<a href="' + event.url + '">'
                            node += 	'<img src="data:image/bmp;base64,' +
                                event.qrCode + '" style="padding: 10px; background: white;">';
                            node += '</a>'

                            document.getElementById('qr-code-block').innerHTML = node;
                            document.getElementById('qr-code-block').style.display = 'block';
                        }

                        /*
                            Очікування зчитування ос. ключа користувачем
                        */
                        euSign.ReadPrivateKey()
                            .then(function(certificates) {
                                return euSign.AddEventListener(
                                    EndUser.EventType.ConfirmKSPOperation,
                                    onConfirmKSPOperation);
                            })
                            .then(function () {
                                document.getElementById('qr-code-block').style.display = 'none';
                                // document.getElementById('sign-widget-parent').style.display = 'none';
                                // document.getElementById('sign-data-block').style.display = 'block';
                            })
                            .catch(function(e) {
                                alert('Виникла помилка при зчитуванні ос. ключа. ' +
                                    'Опис помилки: ' + (e.message || e));
                            });

                        //=============================================================================

                    </script>
                </div>
                <div class="tab_item">
                    <?php echo do_shortcode('[gravityform id="7" title="true"]') ?>
                </div>
            </div>
        </div>
    </main>
<?php get_footer();
