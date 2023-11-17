<template>
    <div>
      {{ this.getValue(this.value) }}
    </div>
</template>

<script>
    export default {
        name: "Number",
        props: {
            value: {
                type: Number,
                default() {
                    return 0;
                }
            }
        },
      methods: {
        getValue(value) {

          if (this.data_type === 'call_out_minutes') {
            return Math.ceil(value / 60);
          }
          if (this.data_type === 'call_in_minutes') {
            return Math.ceil(value / 60);
          }

          return value;
        }
      }
    }
</script>

<style scoped>

</style>