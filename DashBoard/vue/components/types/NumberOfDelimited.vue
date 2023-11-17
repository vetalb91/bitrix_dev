<template>
    <div>
        {{value.join(` / `)}}
<!--        {{value[0]}}-->
    </div>
</template>

<script>
    export default {
        name: "NumberOfDelimited",
        props: {
            value: {
                type: Array,
                default() {
                    return [0,0,0];
                }
            },
            data: {
                type: Object,
                default() {
                    return {
                        options: {
                            separator: ' / '
                        }
                    }
                }
            }
        }
    }
</script>

<style scoped>

</style>