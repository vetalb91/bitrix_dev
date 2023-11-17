<template>
    <div>
        {{[this.data_value, this.data.options.max].join(' / ')}}
    </div>
</template>

<script>
    export default {
        name: "SpeedoNumber",
        props: {
            value: {
                type: Number,
                default() {
                    return 0;
                }
            },
            data: {
                type: Object,
                default() {
                    return {
                        options: {
                            max: 0
                        }
                    }
                }
            },
            data_type: String
        },
        computed: {
            data_value() {
                if (this.data_type === 'call_minutes') {
                    return Math.ceil(this.value/60);
                } else {
                    return this.value;
                }
            }
        }
    }
</script>

<style scoped>

</style>